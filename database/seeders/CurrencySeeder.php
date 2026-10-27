<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * The currencies the application may transact in.
 *
 * TZS is the reporting currency; the others exist so exchange_rates has a
 * valid pair to point at. No rate is seeded here: a rate is a dated fact, not
 * a constant, and belongs to the company that observed it.
 */
class CurrencySeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, symbol: string, decimal_places: int}>
     */
    private array $currencies = [
        ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'decimal_places' => 2],
        ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$', 'decimal_places' => 2],
        ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2],
        ['code' => 'GBP', 'name' => 'Pound Sterling', 'symbol' => '£', 'decimal_places' => 2],
        ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'decimal_places' => 2],
    ];

    public function run(): void
    {
        foreach ($this->currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                [
                    'name' => $currency['name'],
                    'symbol' => $currency['symbol'],
                    'decimal_places' => $currency['decimal_places'],
                    'is_active' => true,
                ]
            );
        }
    }
}
