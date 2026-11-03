<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The ISO 4217 code used when a company has not chosen its own reporting
    | currency. Resolved to a row in the `currencies` table at runtime; the
    | code here is only a fallback, never a hard-coded amount.
    |
    */

    'default_currency' => env('NEXAS_DEFAULT_CURRENCY', 'TZS'),

    'default_country' => env('NEXAS_DEFAULT_COUNTRY', 'TZ'),

    /*
    |--------------------------------------------------------------------------
    | Decimal Scale
    |--------------------------------------------------------------------------
    |
    | Money is stored as DECIMAL(19,4) and quantities as DECIMAL(19,6). These
    | values are the bcmath scales all arithmetic must use; never fall back to
    | native floats for monetary work.
    |
    */

    'money_scale' => 4,
    'quantity_scale' => 6,
    'rounding_mode' => 'half_up',

    /*
    |--------------------------------------------------------------------------
    | Default Company Policy
    |--------------------------------------------------------------------------
    |
    | Fallbacks applied when a company row does not carry its own setting.
    | These are data-driven per company; the values here are defaults only.
    |
    */

    'policy' => [
        // First in, first out is the only method this build values stock with,
        // so it is also the default: a company that keeps this value can move
        // stock, and one set to an unimplemented method is refused out loud
        // rather than quietly costed by a rule it did not ask for.
        'inventory_costing' => env('NEXAS_INVENTORY_COSTING', 'fifo'),
        'allow_negative_stock' => (bool) env('NEXAS_ALLOW_NEGATIVE_STOCK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Exports
    |--------------------------------------------------------------------------
    |
    | A rendered report file is written by a queued job to the disk named
    | below, which lives outside public, is handed back only through a
    | temporary signed route, and is deleted after the retention window. Both
    | figures are configuration rather than constants so a deployment can
    | tighten them without a code change: the retention is how long a finished
    | export stays downloadable, and the link lifetime is how long the signed
    | URL on it stays valid — deliberately shorter, because the file itself
    | outliving its own link is the point of signing it.
    |
    */

    'exports' => [
        'disk' => env('NEXAS_EXPORT_DISK', 'exports'),
        'retention_hours' => (int) env('NEXAS_EXPORT_RETENTION_HOURS', 24),
        'link_ttl_minutes' => (int) env('NEXAS_EXPORT_LINK_TTL_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Documents filed against a record. The disk lives outside public and is
    | handed back only through a short-lived signed route, exactly as a report
    | export is.
    |
    | `allowed_extensions` selects, out of the vocabulary the shared validator
    | knows, which file types this deployment accepts. The vocabulary itself —
    | and, for each extension, the content types that extension is allowed to
    | actually be — lives in the validator rather than here, because a file is
    | accepted by what is inside it and not by what it is called, and that rule
    | is the same on every deployment.
    |
    | `max_kilobytes` is the ceiling a single upload may reach. Deliberately
    | generous: a scanned supplier invoice is a few hundred kilobytes and a
    | photographed delivery note a few megabytes, so the limit is set where a
    | file stops plausibly being a document rather than where it stops being
    | convenient.
    |
    */

    'attachments' => [
        'disk' => env('NEXAS_ATTACHMENT_DISK', 'attachments'),
        'max_kilobytes' => (int) env('NEXAS_ATTACHMENT_MAX_KILOBYTES', 10240),
        'link_ttl_minutes' => (int) env('NEXAS_ATTACHMENT_LINK_TTL_MINUTES', 30),
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'csv', 'xlsx'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Imports
    |--------------------------------------------------------------------------
    |
    | The spreadsheets a company brings its opening data in on: a chart of
    | accounts, a party list, a product list, opening stock and opening
    | balances. The disk lives outside public, exactly as a statement or an
    | attachment does, because until a file is committed it is a draft of the
    | books that nobody has agreed to yet.
    |
    | `max_kilobytes` is the ceiling one import file may reach. Larger than the
    | statement ceiling, because a chart of accounts and a product catalogue
    | are both longer than a year of a busy bank account as CSV.
    |
    */

    'imports' => [
        'disk' => env('NEXAS_IMPORT_DISK', 'imports'),
        'max_kilobytes' => (int) env('NEXAS_IMPORT_MAX_KILOBYTES', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Policy
    |--------------------------------------------------------------------------
    |
    | How hard it is to get in, how long an answer stays good, and what a
    | password has to look like. Every figure is configuration rather than a
    | constant so a deployment can tighten it without a code change — the
    | defaults here are the floor this build ships with, not a ceiling.
    |
    | `login_max_attempts` counts failures per email-and-address pair rather
    | than per address alone, so one noisy tenant cannot lock out a shared
    | office connection, and a distributed guess against a single account is
    | still counted where it lands.
    |
    */

    'auth' => [
        'login_max_attempts' => (int) env('NEXAS_LOGIN_MAX_ATTEMPTS', 5),
        'login_decay_seconds' => (int) env('NEXAS_LOGIN_DECAY_SECONDS', 60),

        // How long an issued API token stays usable. Null or zero means the
        // token never expires on its own and lives until it is revoked; the
        // shipped default is a working week, because a token that outlives the
        // laptop it was minted on is a credential nobody is watching.
        'token_lifetime_minutes' => (int) env('NEXAS_TOKEN_LIFETIME_MINUTES', 10080),

        // How long a token issued by a "Remember me" sign-in stays usable.
        // Longer than the ordinary lifetime because that is exactly what the
        // box asks for, and still finite so a remembered credential on a
        // machine nobody watches stops working on its own. The pair lives here
        // so a deployment can set both without a code change; zero keeps the
        // ordinary rule, since a box cannot make a token outlive the policy.
        'remember_token_lifetime_minutes' => (int) env('NEXAS_REMEMBER_TOKEN_LIFETIME_MINUTES', 43200),

        'password_min_length' => (int) env('NEXAS_PASSWORD_MIN_LENGTH', 12),

        'two_factor' => [
            // The digits a TOTP code is made of and how many 30-second steps
            // either side of now are accepted. One step of drift forgives a
            // slightly fast or slow clock without widening the guessing window
            // beyond a minute.
            'digits' => 6,
            'period' => 30,
            'window' => 1,

            // How long the half-finished login a password alone produces stays
            // exchangeable for a real token. Short, because it is a credential
            // in flight and not a session.
            'challenge_ttl_minutes' => (int) env('NEXAS_TWO_FACTOR_CHALLENGE_TTL_MINUTES', 5),

            // How many code attempts a minute a caller gets, counted per
            // member where there is one. Deliberately looser than the login
            // limiter: a six-digit code guessed at this rate would take months
            // to hit, while a caller fumbling the code off their own phone
            // should not be locked out of their own account.
            'max_attempts' => (int) env('NEXAS_TWO_FACTOR_MAX_ATTEMPTS', 10),

            'recovery_code_count' => 8,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | One encrypted file a night holding the whole database and the documents
    | filed against it, written outside public and readable by nobody without
    | the key below.
    |
    | `key` is the whole of the safety of the file at rest. A backup holds
    | every journal, every party and every filed document, and its only
    | protection is this secret, so it is a key of its own rather than the
    | application's: rotating APP_KEY must not make last month's backups
    | unreadable. It is a base64 string of 32 bytes, `base64:` prefix
    | optional, and it is never committed. A run without it refuses to write
    | a backup at all, rather than writing the books out where anyone with
    | the disk can read them.
    |
    | The server holds this key, because the server is what encrypts each
    | night's backup. What must not be true is that the key lives only here:
    | the owner keeps a second copy off the server. A backup and its key
    | sitting on the same disk are one failure away from being nothing at all
    | — see docs/backups.md for where the copy lives and the drill that proves
    | it is the right one.
    |
    | `retention_days` is how long a nightly file stays on the disk, and
    | `keep` is a floor beneath it as well as a ceiling: pruning never takes
    | the newest `keep` files, so a run of failures cannot leave the company
    | with no backup at all, and a month of quiet nights cannot be pruned
    | down to an empty folder.
    |
    | `include` names the disks whose files travel inside the bundle. Rendered
    | report exports are deliberately absent: an export is a report that can
    | be rebuilt from the ledger inside the backup, so carrying a copy of it
    | would only make the file larger and the restore slower.
    |
    | `scratch_database_prefix` is the shape of the throwaway database a
    | restore drill loads into. A restore refuses a target that is not named
    | this way and refuses the database the application is currently
    | connected to, so a drill can never write over the books it is checking.
    |
    | `mysqldump` and `mysql` are the client binaries. Left as bare names they
    | are found on PATH, which is where a deployment should have them; the
    | full paths are for a machine that keeps them elsewhere.
    |
    */

    'backups' => [
        'disk' => env('NEXAS_BACKUP_DISK', 'backups'),
        'key' => env('NEXAS_BACKUP_KEY'),
        'retention_days' => (int) env('NEXAS_BACKUP_RETENTION_DAYS', 30),
        'keep' => (int) env('NEXAS_BACKUP_KEEP', 7),
        'include' => ['attachments', 'statements'],
        'scratch_database_prefix' => (string) env('NEXAS_BACKUP_SCRATCH_PREFIX', 'nexas_restore_'),
        'mysqldump' => env('NEXAS_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('NEXAS_MYSQL_BINARY', 'mysql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | The two ceilings the approvals queue works under, both configuration
    | rather than constants so a deployment can set them without a code change.
    |
    | `normal_threshold` is the value above which a document is flagged for a
    | second look. It is a warning and not a gate: the Financial Manager still
    | decides an amount beyond it, because a ceiling the queue cannot cross
    | would only move the decision somewhere it is not recorded.
    |
    | `bulk_limit` is the most value one bulk approval may carry in total, and
    | `bulk_max_items` the most documents it may name. Both are enforced on the
    | server against the documents themselves, so a caller cannot raise either
    | by changing what the screen sends.
    |
    */

    'approvals' => [
        'normal_threshold' => (float) env('NEXAS_APPROVAL_THRESHOLD', 10000000),
        'bulk_limit' => (float) env('NEXAS_APPROVAL_BULK_LIMIT', 50000000),
        'bulk_max_items' => (int) env('NEXAS_APPROVAL_BULK_MAX_ITEMS', 50),
    ],

];
