# Hosting and deployment (Stage 11B)

What the application needs from a server, which configuration files in
`deploy/` go where, and what is **not verified** until it has been run on a
real machine. The business continuity side — the nightly backup, the restore
drill and the key that makes a backup readable — is in
[backups.md](backups.md); this document only covers the machine around it.

## The machine

Ubuntu 24.04 LTS on a single host: Nginx in front, PHP 8.4-FPM behind it, MySQL
8.4 and Redis on the same box, Supervisor keeping the queue workers and the
scheduler alive. That is the whole of it — there is no load balancer, no
separate database host and no object storage, which is deliberate at this size
and is what makes the layout below as simple as it is.

| Item | Starting point | What pushes it up |
| --- | --- | --- |
| CPU | 2 vCPU | Report generation. The nightly ledger and audit verifications and the 03:15 backup all run on this box |
| Memory | 4 GB | PHP-FPM children (12 × ~40–60 MB), the MySQL InnoDB buffer pool (1 GB), Redis |
| Disk | 80 GB SSD | The backup window is the real driver — see below |
| Bandwidth | A few GB a month | Attachments and bank statement uploads, and report downloads |

Memory is the figure to watch, and the arithmetic is worth writing down because
it is not obvious from any single number: a loaded PHP-FPM child holds 40–60 MB
of framework, twelve of them can be alive at once
([php-fpm-pool.conf](../deploy/php/php-fpm-pool.conf) sets `pm.max_children = 12`),
and MySQL wants its buffer pool on top of that. Three gigabytes is the
realistic peak, so 4 GB is a floor rather than a comfortable choice.
`pm.max_children` is the dial — lowering it to 8 frees roughly 200 MB and costs
concurrency the API will not miss.

Disk is driven by the backups. The bundle is not compressed: it carries the
`mysqldump` output and the filed documents as they are, so one night costs
approximately the size of the dump plus the size of the two document disks.
Multiply that by `NEXAS_BACKUP_RETENTION_DAYS` (30 by default) and leave room
for the newest `NEXAS_BACKUP_KEEP` (7) beyond it. **Measure it on the server
rather than guessing** — `du -sh storage/app/private/backups` after the first
week tells you the real number, and it is the one number in this document that
should be checked before the disk decides it for you.

## What is installed

```
apt install nginx mysql-server-8.4 redis-server supervisor git rsync unzip \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-redis \
    certbot python3-certbot-nginx
```

| Package | Why it is here |
| --- | --- |
| `nginx` | Terminates TLS and serves `public/` |
| `mysql-server-8.4` | The ledger. 8.4 rather than 8.0 because the schema uses `CHECK` constraints and triggers that the application relies on |
| `redis-server` | The queue, the cache and the session store |
| `supervisor` | Keeps the two queue workers and the scheduler alive across a crash and a reboot |
| `php8.4-fpm` | The pool Nginx hands a request to |
| `php8.4-bcmath` | Every monetary calculation. `config/nexas.php` names the bcmath scales (`money_scale` 4, `quantity_scale` 6); without this extension the application does not run |
| `php8.4-mysql` | `pdo_mysql` |
| `php8.4-xml`, `php8.4-mbstring` | Laravel itself, and the DOM the PDF renderer needs |
| `php8.4-gd` | Images inside a rendered PDF export |
| `php8.4-redis` | The `phpredis` client, which `REDIS_CLIENT=phpredis` asks for |
| `php8.4-zip`, `unzip` | Composer installs distributions from a zip |
| `git`, `rsync` | Getting the code onto the box, and the release script's copy |
| `certbot` | The certificate |

Composer is installed separately, from
[getcomposer.org](https://getcomposer.org/download/) into
`/usr/local/bin/composer`.

Node is **not** in the list. See "Front-end assets" below for why, and for the
one case where it has to be added.

## Users and ownership

Two identities, and neither of them is `root`:

| Identity | Purpose |
| --- | --- |
| `nexas` | Owns the application files and runs PHP-FPM, the queue workers and the scheduler |
| `www-data` | Nginx. Reads `public/`; never writes anywhere in the application tree |

Nginx reads the build output and nothing else. The application user belongs to
`www-data` as a secondary group so that the socket PHP-FPM creates can be
opened by both sides, which is what `listen.owner`/`listen.group` in
[php-fpm-pool.conf](../deploy/php/php-fpm-pool.conf) arrange.

The MySQL account the application connects with is its own — `nexas`, not
`root`. It needs `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `EXECUTE`, `SHOW VIEW`
and the right to create and drop triggers, because the audit chain and the
stock rules are enforced by triggers rather than by application code. It also
needs the scratch-database grant the restore drill uses, which is written out
in [backups.md](backups.md).

## The layout

```
/var/www/nexas/
├── source/                 the checked-out repository (what you pull into)
├── releases/
│   └── 20261031-120000/    the code of one release
├── shared/
│   ├── .env                copied once from .env.staging.example
│   └── storage/
│       ├── app/private/    attachments, bank-statements, exports, backups
│       ├── app/public/     the only uploads a browser may fetch directly
│       ├── framework/      compiled views, cache, sessions
│       ├── logs/
│       └── fonts/          the PDF renderer's font cache
└── current -> releases/20261031-120000
```

`current` is the reason a deploy is invisible: Nginx and Supervisor both
point at it, and it is one symlink, so activating a release is a single atomic
operation. The `storage/` directory is shared rather than per-release because it
holds state and not code — last night's backup, the documents filed against
last week's journals, and the compiled views. Everything that is code lives
inside a release and is replaced wholesale.

`fonts/` deserves a note, because it looks like a cache and is not one to
throw away: `config/dompdf.php` points `font_dir` and `font_cache` at
`storage_path('fonts')`. Sharing it means a PDF is rendered once rather than
rebuilt on the first request after every deploy.

## The services

Each file in `deploy/` is a copy of a real configuration file, and each says at
the top where it goes.

| File in this repository | Goes to | Enable / reload with |
| --- | --- | --- |
| [deploy/nginx/nexas.conf](../deploy/nginx/nexas.conf) | `/etc/nginx/sites-available/nexas.conf` | `ln -s` into `sites-enabled/`, then `nginx -t && systemctl reload nginx` |
| [deploy/php/php-fpm-pool.conf](../deploy/php/php-fpm-pool.conf) | `/etc/php/8.4/fpm/pool.d/nexas.conf` | `systemctl reload php8.4-fpm` |
| [deploy/php/99-nexas.ini](../deploy/php/99-nexas.ini) | `/etc/php/8.4/fpm/conf.d/` **and** `/etc/php/8.4/cli/conf.d/` | `systemctl reload php8.4-fpm` |
| [deploy/supervisor/nexas.conf](../deploy/supervisor/nexas.conf) | `/etc/supervisor/conf.d/nexas.conf` | `supervisorctl reread && supervisorctl update` |

Two things in that table are easy to get wrong and are worth stating plainly:

- **The `99-nexas.ini` copy is two copies.** The CLI one is not optional: the
  queue worker renders the PDF exports and the scheduler takes the nightly
  backup, and if they run under a smaller memory limit or a shorter execution
  time than the web tier, the failure appears in the queue rather than in a
  request anyone can see.
- **`date.timezone` is set in three places and they must agree.** The INI sets
  it for the CLI and the log timestamps, `APP_TIMEZONE` in `.env` is the one
  the framework uses, and the server itself should be on UTC with those two
  pointing at `Africa/Dar_es_Salaam`. The nightly schedule — the ledger
  verification, the audit verification and the 03:15 backup — is read in the
  application's zone, so a `.env` left without `APP_TIMEZONE` would take the
  backup at 06:15 Dar es Salaam time. That gap is why this deployment has a
  `.env.staging.example` of its own.

### One scheduler, not two

The nightly work must run once. `nexas-scheduler` in
[deploy/supervisor/nexas.conf](../deploy/supervisor/nexas.conf) is
`numprocs = 1` for that reason, and the file records the cron alternative for a
host that would rather not run Supervisor at all. **Run one or the other, never
both**: two schedulers would run the ledger verification, the audit
verification and the backup twice a night, and a duplicated backup wastes the
disk and the window without anyone noticing.

### Network exposure

| Port | Reachable from | What is behind it |
| --- | --- | --- |
| 22 | The operator's address only | SSH, key-only, no password login |
| 80 | Anywhere | A redirect to HTTPS and the certificate challenge |
| 443 | Anywhere | Nginx |

MySQL and Redis are **not** in that table on purpose. Both listen on
`127.0.0.1` only — `bind-address` in `mysqld.cnf` and `bind 127.0.0.1` in
`redis.conf` — and neither is exposed to the network at all. `.env` reaching
them on `127.0.0.1` is what makes that possible; an application host and a
database host on the same machine is the reason there is no TLS between them to
configure.

### TLS

Certbot issues the certificate and the renewal timer keeps it. Nginx is
configured for it already — the ACME challenge path is served from
`/var/www/letsencrypt` before the redirect, so a renewal does not need the site
taken down or the redirect loosened.

```
certbot --nginx -d staging.example.co.tz
```

Replace `staging.example.co.tz` in both the certificate command and
`deploy/nginx/nexas.conf` before the first attempt.

## The environment file

`.env` is **not** in a release. It is created once at `shared/.env` and
symlinked into every release, so an environment value survives a deploy and a
rollback alike.

```
cp .env.staging.example /var/www/nexas/shared/.env
# edit it: hostname, database password, and NEXAS_BACKUP_KEY
php artisan key:generate
```

`APP_KEY` and `NEXAS_BACKUP_KEY` are different keys with different jobs, and
both have to exist:

- `APP_KEY` encrypts cookies and anything the framework encrypts. Losing it
  logs everyone out.
- `NEXAS_BACKUP_KEY` is the whole of the safety of every backup file. The
  server holds it, because the server is what encrypts each night's backup —
  this `.env` is where the key does that job, and a run without it writes
  nothing. What must not happen is the key living **only** here: **the owner
  keeps a second copy off this machine** — see
  [backups.md](backups.md#where-the-second-copy-lives). A backup without its
  key cannot be restored, so a key kept only beside the backups is one failure
  from being no key at all.

## First deploy

```
# as root, once
adduser --system --group --home /var/www/nexas nexas
usermod -aG www-data nexas
mkdir -p /var/www/nexas/{source,releases,shared}
mkdir -p /var/log/nexas
chown -R nexas:www-data /var/www/nexas /var/log/nexas

# as nexas
git clone <repository> /var/www/nexas/source
cp /var/www/nexas/source/.env.staging.example /var/www/nexas/shared/.env
# edit /var/www/nexas/shared/.env  — see above

# then, with the four configuration files from deploy/ in place:
sudo -u nexas /var/www/nexas/source/deploy/release.sh
```

The release script creates the runtime directories inside `shared/storage` if
they are missing, so the first run needs nothing prepared beyond `.env` and the
four server configuration files. It refuses to start if either is absent,
naming which one.

Verify it came up:

```
curl -sf https://staging.example.co.tz/up -o /dev/null && echo up
php /var/www/nexas/current/artisan schedule:list
supervisorctl status
```

The `/up` endpoint is the application's own health route. It is kept out of the
Nginx access log deliberately — a load balancer polling it every few seconds
would bury everything else — so a `200` from `curl` is the whole of the check.

## Deploying a change

```
sudo -u nexas git -C /var/www/nexas/source pull
sudo -u nexas /var/www/nexas/source/deploy/release.sh
```

[release.sh](../deploy/release.sh) copies the source into a new timestamped
release, shares `.env` and `storage`, installs production dependencies without
dev packages, runs `migrate --force`, caches the config, events, routes and
views, swaps `current`, reloads PHP-FPM and restarts the queue workers.

Two details in that sequence are load-bearing:

- **PHP-FPM is reloaded, not restarted.** `opcache.validate_timestamps = 0`
  ([99-nexas.ini](../deploy/php/99-nexas.ini)) means a running worker never
  re-reads a file it has already loaded. Without the reload, the old release
  would go on answering with `current` pointing at the new one — the deploy
  would appear to have done nothing.
- **`queue:restart` is what moves the workers.** They are long-lived processes
  that loaded their code at boot; the restart asks each to finish the job in
  hand and exit, and Supervisor starts it again against the new release.

A migration in the same release runs while the old release is still answering,
which is safe for an additive change and only for an additive change. For a
migration that drops or renames a column, run with
`NEXAS_MAINTENANCE_ON_MIGRATE=1`, which takes the site into maintenance around
the migration.

### Rollback

The previous releases are still on disk with their `vendor/` installed, so a
rollback is a symlink and a reload:

```
ln -sfn /var/www/nexas/releases/<previous> /var/www/nexas/current
systemctl reload php8.4-fpm
sudo -u nexas php /var/www/nexas/current/artisan queue:restart
```

What that does **not** undo is a migration that has already run. That is the
whole reason the maintenance flag and a database backup exist before a
destructive change.

## Front-end assets

`public/build` is generated and is not in the repository. It has to come from
somewhere, and there are two ways:

- **Built in CI** and shipped with the release. Nothing extra on the server;
  this is what `NEXAS_BUILD_ASSETS=0` (the default) assumes.
- **Built on the server**, with `NEXAS_BUILD_ASSETS=1`. This runs
  `npm ci && npm run build` inside the new release and requires Node and npm to
  be installed on the host — the one case where the package list above is not
  enough.

Leave the flag off only if the assets really are arriving with the code.
A release with no `public/build` serves an unstyled application, and
`resources/views/welcome.blade.php` calls `@vite(...)`, which throws rather than
degrading quietly.

## What is not verified here

Everything in this document was written against the application's real
configuration — the queue driver and the queue name, the session and cookie
settings, the 10 MB attachment ceiling, the private disks, the `/up` route and
the timezone the nightly schedule is read in — but none of it has been run on a
server, because there is no server. Specifically:

- **Every file in `deploy/` is unrun.** `nginx -t`, `php-fpm -t` and a
  `supervisorctl reread` are the checks that prove them, and none has been run.
- **The release script has never executed.** It is `bash` and has not been
  syntax-checked beyond reading. Run `bash -n deploy/release.sh` on the server
  before the first real release.
- **The sizing numbers are arithmetic, not measurement.** The memory peak is
  calculated from `pm.max_children` and a typical loaded child; the disk
  requirement depends entirely on the real size of the dump and the documents.
  Both should be confirmed against the running host.
- **The certificate, the firewall and the MySQL scratch-database grant** are
  operational steps on the host. Nothing in this repository applies or verifies
  them.
- **The off-server backup copy and the second copy of the backup key** are
  manual, and are recorded in
  [backups.md](backups.md#where-the-second-copy-lives). Until that table is
  filled in, they are not verified.
