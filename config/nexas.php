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

];
