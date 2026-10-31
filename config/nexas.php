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

];
