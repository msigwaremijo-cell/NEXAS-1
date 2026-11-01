# Backups (Stage 10F)

A backup is one encrypted file a night holding the whole database and the
documents filed against it. It is written outside `public`, readable by nobody
without the key it was written with, and it is proven restorable by a drill that
runs against the real file without touching the books the application is trading
on.

## What travels

| Part | Where it comes from | Why |
| --- | --- | --- |
| `database.sql` | `mysqldump --single-transaction --routines --triggers` | Every table and — the part that matters — every trigger |
| `attachments/…` | the `attachments` disk | Documents filed beside journals, invoices and the rest |
| `statements/…` | the `statements` disk | Bank statement uploads |

Report exports are deliberately **not** in the bundle. A rendered export is a
report that can be rebuilt from the ledger inside the backup, so carrying a copy
of it would only make the file larger and the restore slower. Which disks travel
is `nexas.backups.include` in `config/nexas.php`.

The triggers are the reason the dump is taken by `mysqldump` rather than through
the application's own connection. They are what refuse an unbalanced journal and
an edit to a posted one, and a dump without them would restore a database that
looks right and behaves wrong — every row present, the trial balance agreeing,
right up to the first bad entry posted into the restored copy.

## Where the files live

Files are written to the private `backups` disk, rooted at
`storage/app/private/backups` — outside `public` and configured not to be served
by the web server. Each file is named `<date>-<time>-<ulid>.nxb`; the extension
`.nxb` marks it as an encrypted Nexas backup.

`nexas.backups.retention_days` is how long a file stays on the disk, and
`nexas.backups.keep` is a **floor** beneath it. Pruning never takes the newest
`keep` files, however old they are, so a run of failures, a clock that moved, or
a fortnight of quiet nights cannot leave the company with no backup at all. The
row stays when the file goes: that a copy was taken on that night outlives the
copy.

## The key

The whole safety of the file at rest is `NEXAS_BACKUP_KEY`. It is a key of its
own rather than the application's — rotating `APP_KEY` must not make last
month's backups unreadable.

- Generate one with:
  `php -r "echo 'base64:'.base64_encode(random_bytes(32));"`
- It is a base64 string of 32 bytes; the `base64:` prefix is optional.
- It is **never committed**. `.env.example` carries the name and a placeholder
  only, and `.env` is ignored by git.
- A run with no key **refuses to write a backup at all** and writes no row,
  rather than writing every company's books out in the clear. The refusal names
  the missing variable and the command above.

### Where the second copy lives

The server holds `NEXAS_BACKUP_KEY` in its own `.env`, because the server is
what encrypts each night's backup — a run without the key on the machine writes
nothing at all, which is the refusal described above. That is the key doing its
daily job, and it is meant to be there.

What must not be true is that the key exists **only** there. A backup and its
key sitting on the same disk are one failure away from being nothing at all: a
copy of the books with no way to open them. So the owner keeps a **second copy
off the server** — not a replacement for the server's copy, but a copy of the
same key, held where the server cannot reach it. That is a manual step: it
cannot be automated from this repository, and it is not proven by any test here.

Record where the copy is held:

| Field | Value |
| --- | --- |
| Second copy held by (the owner) | _to be completed by the owner_ |
| Where (e.g. password manager entry, sealed offline copy) | _to be completed by the owner_ |
| Date recorded | _to be completed by the owner_ |
| Drill that proves the copy is the right one | `php artisan backups:restore` — run with the key copy, not the server's |

Until that table is filled in, the second copy is **not verified**. The check
that it is the right one is the restore drill below: run it with the copy from
off the server, and it must open the newest backup and agree with the books.

## The restore drill

```
php artisan backups:restore [backup] [--target=] [--key=]
```

It restores into a **scratch** database, compares it with the source, and drops
the scratch database on the way out whatever happened. It never writes into the
live database and never puts a restored document back over a live one, which is
why it is safe to run on a real server against a real backup.

It refuses to run into the database the application is reading its own books
from, and it only ever writes into a database named with the
`nexas.backups.scratch_database_prefix` (`nexas_restore_`). A mistyped
`--target` therefore cannot overwrite working books.

What it compares, and why:

| Check | What it would catch |
| --- | --- |
| Tables | A table that did not come back |
| Rows, table by table | A table that came back empty or short |
| Trial balance (debits, credits, accounts) | The rows came back but the *numbers* did not |
| Triggers | A dump that lost the posting rules — invisible to every row count |
| Documents | The files did not travel with the database |

The trial balance is read through the statuses that belong in the ledger —
posted and reversed, never posted alone — so a set of books that has ever been
corrected still agrees after a restore.

Any check that does not match makes the command exit non-zero, so an unattended
drill that found the backups were not restorable leaves a trace.

```
php artisan backups:run
php artisan backups:restore
```

`backups:run` is scheduled at 03:15, last of the night's work: the read-only
ledger and audit verifications finish by 02:45, so by the time the copy is taken
a broken ledger or a tampered audit trail has already been reported rather than
being archived with the copy. It runs whether or not those verifications passed
— a night with a problem in it is not a night to stop keeping a backup of.

## Not verified on a development machine

- **The scratch-database grant.** `nexas_app` has no global `CREATE DATABASE`,
  so restoring a drill into a scratch database needs:
  `GRANT ALL PRIVILEGES ON \`nexas_restore\_%\`.* TO 'nexas_app'@'127.0.0.1';`
  followed by `FLUSH PRIVILEGES;`. Applying that grant is a manual step on each
  server and is **not verified** by this repository.
- **Off-server storage.** Copying the encrypted files off the server, and the
  second copy of the key, are operational steps. Nothing here verifies either.
- **Scheduling.** The scheduler entry is written and tested, but that the
  cron/`schedule:run` entry actually fires on a given server is **not verified**
  here. Confirm with `php artisan schedule:list` on the server.

Where the MySQL client tools are not on `PATH`, name them in full with
`NEXAS_MYSQLDUMP_BINARY` and `NEXAS_MYSQL_BINARY`. A run without them cannot take
a dump or a restore, and the backup tests skip with a clear reason rather than
passing quietly.
