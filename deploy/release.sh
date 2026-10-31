#!/usr/bin/env bash
#
# Nexas — release script
#
# Builds a new release beside the one that is serving, and swaps a symlink when
# the new one has migrated and cached cleanly. Nothing is swapped until then,
# so a run that fails half way leaves the previous release serving.
#
# The layout it expects (see docs/deployment.md):
#
#   /var/www/nexas/
#     source/              the checked-out repository; this script lives in it
#     releases/
#       20261031-120000/   the code of one release
#     shared/
#       .env               the environment, copied once from .env.staging.example
#       storage/           attachments, statements, exports, backups, logs, fonts
#     current -> releases/<newest>
#
# Run it from the repository root as a user that may write the release tree and
# may reload PHP-FPM:
#
#     sudo -u nexas /var/www/nexas/source/deploy/release.sh
#
# Everything it needs to know can be overridden in the environment; the
# defaults match the paths in deploy/nginx/nexas.conf and
# deploy/supervisor/nexas.conf, so changing one without the other breaks the
# site rather than the script.

set -euo pipefail

APP_ROOT="${NEXAS_APP_ROOT:-/var/www/nexas}"
APP_USER="${NEXAS_APP_USER:-nexas}"
APP_GROUP="${NEXAS_APP_GROUP:-www-data}"

SOURCE_DIR="${NEXAS_SOURCE_DIR:-$APP_ROOT/source}"
RELEASES_DIR="$APP_ROOT/releases"
SHARED_DIR="$APP_ROOT/shared"
CURRENT_LINK="$APP_ROOT/current"
RELEASE_DIR="$RELEASES_DIR/$(date -u +%Y%m%d-%H%M%S)"

PHP_BIN="${NEXAS_PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${NEXAS_COMPOSER_BIN:-composer}"
FPM_SERVICE="${NEXAS_FPM_SERVICE:-php8.4-fpm}"
PHP_FPM_BIN="${NEXAS_PHP_FPM_BIN:-/usr/sbin/php-fpm8.4}"

# How many old releases to keep on disk. Each is a few tens of megabytes of
# code once vendor/ is installed, so this is a cheap rollback window rather
# than a storage decision.
KEEP_RELEASES="${NEXAS_KEEP_RELEASES:-5}"

# Set to 1 when the release contains a migration that the release currently
# serving would not survive — a dropped or renamed column, for instance. The
# site is taken into maintenance for the migration and brought back up after
# the swap. Left at 0 a purely additive migration runs with the old release
# still answering, which is what makes an ordinary deploy invisible.
MAINTENANCE_ON_MIGRATE="${NEXAS_MAINTENANCE_ON_MIGRATE:-0}"

# Set to 1 to run `npm ci && npm run build` on the server. Normally left at 0:
# the built assets in public/build carry a content hash in their filename and
# are built once in CI, so the server does not need Node at all.
BUILD_ASSETS="${NEXAS_BUILD_ASSETS:-0}"

log() { printf '[release] %s\n' "$*"; }
fail() { printf '[release] ERROR: %s\n' "$*" >&2; exit 1; }

artisan() {
    # Run artisan from the release being built, not from `current`, so the
    # caches and the migration are made against the new code.
    ( cd "$RELEASE_DIR" && "$PHP_BIN" artisan "$@" )
}

# --- Preflight -------------------------------------------------------------
#
# Everything that would otherwise fail after the tree has been copied is
# checked first, so a misconfigured environment fails in a second rather than
# in the middle of an install.

[ -d "$SOURCE_DIR" ] || fail "no source tree at $SOURCE_DIR (set NEXAS_SOURCE_DIR)"
[ -f "$SOURCE_DIR/artisan" ] || fail "$SOURCE_DIR is not a Laravel application"
command -v "$PHP_BIN" >/dev/null 2>&1 || fail "php not found at $PHP_BIN"
command -v "$COMPOSER_BIN" >/dev/null 2>&1 || fail "composer not found at $COMPOSER_BIN"
command -v rsync >/dev/null 2>&1 || fail "rsync is not installed"

[ -d "$SHARED_DIR" ] || fail "no $SHARED_DIR (create it and copy .env.staging.example into $SHARED_DIR/.env, see docs/deployment.md)"
[ -f "$SHARED_DIR/.env" ] || fail "no $SHARED_DIR/.env — the environment is shared and must exist before the first release"

# The directories Laravel writes to at runtime. Created on the first deploy and
# left alone afterwards, because they hold the only copy of the filed
# documents, the backups and the compiled views.
mkdir -p \
    "$SHARED_DIR/storage/app/private/attachments" \
    "$SHARED_DIR/storage/app/private/bank-statements" \
    "$SHARED_DIR/storage/app/private/exports" \
    "$SHARED_DIR/storage/app/private/backups" \
    "$SHARED_DIR/storage/app/public" \
    "$SHARED_DIR/storage/framework/cache/data" \
    "$SHARED_DIR/storage/framework/sessions" \
    "$SHARED_DIR/storage/framework/views" \
    "$SHARED_DIR/storage/logs" \
    "$SHARED_DIR/storage/fonts"

mkdir -p "$RELEASES_DIR"

# --- Build -----------------------------------------------------------------

log "building $RELEASE_DIR"

# The source tree is copied rather than cloned, so the script works equally
# from a git checkout and from an archive CI unpacked. vendor/ is copied too
# when it is there; the composer step below then only has to check it.
rsync -a \
    --exclude '.git' \
    --exclude '.env' \
    --exclude '.env.*' \
    --exclude 'storage' \
    --exclude 'node_modules' \
    --exclude 'public/hot' \
    --exclude 'tests' \
    --exclude '.github' \
    "$SOURCE_DIR/" "$RELEASE_DIR/"

# The environment and everything the application writes to are shared between
# releases: a new release must see last night's backup, the documents filed
# against yesterday's journals, and the fonts the PDF renderer cached. fonts/
# is inside storage/ for exactly that reason — dompdf writes its cache there
# (config/dompdf.php) and would rebuild it on every deploy otherwise.
ln -sfn "$SHARED_DIR/.env" "$RELEASE_DIR/.env"
rm -rf "$RELEASE_DIR/storage"
ln -sfn "$SHARED_DIR/storage" "$RELEASE_DIR/storage"

# public/storage is the one directory under the document root that is not
# code. Pointed at the shared tree so an uploaded file survives a release.
if [ -d "$SOURCE_DIR/public/storage" ] && [ ! -L "$SOURCE_DIR/public/storage" ]; then
    rm -rf "$RELEASE_DIR/public/storage"
fi
ln -sfn "$SHARED_DIR/storage/app/public" "$RELEASE_DIR/public/storage"

if [ "$BUILD_ASSETS" = "1" ]; then
    log "building front-end assets"
    ( cd "$RELEASE_DIR" && npm ci && npm run build )
fi

log "installing composer dependencies"
( cd "$RELEASE_DIR" && "$COMPOSER_BIN" install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-progress )

# --- Migrate ---------------------------------------------------------------
#
# The migration runs against the release being built, with the old release
# still serving. That is safe for an additive change and only for an additive
# change; NEXAS_MAINTENANCE_ON_MIGRATE=1 covers the other kind.
#
# PHP-FPM has not been reloaded yet, so the workers started a moment ago are
# still running the previous code; the only new code touching the database at
# this point is the migration itself.

if [ "$MAINTENANCE_ON_MIGRATE" = "1" ]; then
    log "putting the site into maintenance for the migration"
    artisan down --retry=15 || true
fi

log "running migrations"
artisan migrate --force --no-interaction

if [ "$MAINTENANCE_ON_MIGRATE" = "1" ]; then
    artisan up || true
fi

# --- Cache -----------------------------------------------------------------
#
# Cached rather than left to the autoloader: the config, route and view caches
# are the difference between reading a few files and a few hundred on every
# request. They are written into this release's bootstrap/cache, so they are
# rebuilt here rather than inherited from the last one.
#
# A failure here is fatal on purpose. A half-cached release is worse than no
# release, and `current` has not moved yet, so failing now costs nothing.

log "caching config, events, routes and views"
artisan config:cache
artisan event:cache
artisan route:cache
artisan view:cache

# --- Swap ------------------------------------------------------------------

log "activating the new release"
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"

# PHP-FPM is reloaded, not restarted, and it is not optional.
# opcache.validate_timestamps is 0 (deploy/php/99-nexas.ini), so a running
# worker keeps the code it loaded the first time it ran — without the reload
# the old release would go on answering despite `current` pointing at the new
# one. A reload lets the children finish the requests they are in the middle
# of and only then picks up the new code.
log "reloading $FPM_SERVICE"
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "$FPM_SERVICE"; then
    systemctl reload "$FPM_SERVICE"
elif [ -x "$PHP_FPM_BIN" ] && [ -f /run/php/php8.4-fpm.pid ]; then
    # The fallback for a host without systemd. php-fpm -USR2 signals the
    # running master, which is the same graceful reload systemctl asks for.
    "$PHP_FPM_BIN" -USR2
else
    fail "could not reload PHP-FPM — reload it by hand and set NEXAS_FPM_SERVICE"
fi

# The queue workers are long-lived processes started from `current`; they only
# move to the new code when they are restarted. queue:restart asks each one to
# finish the job in hand and exit, and Supervisor starts it again.
log "restarting queue workers"
artisan queue:restart

# --- Ownership and pruning -------------------------------------------------

if [ "$(id -u)" -eq 0 ]; then
    chown -R "$APP_USER:$APP_GROUP" "$RELEASE_DIR"
    chown -R "$APP_USER:$APP_GROUP" "$SHARED_DIR/storage"
    chmod -R u+rwX,g+rwX "$SHARED_DIR/storage"
fi

# The backup taken last night is not touched by any of this; it is inside
# shared/storage and no release owns it. What is pruned is old releases only,
# and never the one `current` points at.

cd "$RELEASES_DIR"
OLD_RELEASES=$(ls -1dt -- */ 2>/dev/null | sed 's:/$::' | tail -n +$((KEEP_RELEASES + 1)) || true)

if [ -n "$OLD_RELEASES" ]; then
    while read -r release; do
        [ -n "$release" ] || continue
        [ "$RELEASES_DIR/$release" = "$(readlink -f "$CURRENT_LINK")" ] && continue
        log "removing old release $release"
        rm -rf "$RELEASES_DIR/$release"
    done <<< "$OLD_RELEASES"
fi

log "done — $CURRENT_LINK -> $RELEASE_DIR"

# Note on rollback: the previous release is still on disk and still has its
# vendor/ installed. Pointing `current` back at it and reloading PHP-FPM is
# the whole of the rollback, so it takes a second. What it does not undo is a
# migration that has already run; that is what NEXAS_MAINTENANCE_ON_MIGRATE
# and a database backup taken before a destructive change are for.
