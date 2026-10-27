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
        'inventory_costing' => env('NEXAS_INVENTORY_COSTING', 'weighted_average'),
        'allow_negative_stock' => (bool) env('NEXAS_ALLOW_NEGATIVE_STOCK', false),
    ],

];
