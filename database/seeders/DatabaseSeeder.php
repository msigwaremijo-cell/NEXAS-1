<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the demo tenant in dependency order.
 *
 * Note the absence of WithoutModelEvents: the ULID on every business row is
 * filled by the HasPublicId `creating` listener, and muting model events would
 * leave those columns null.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            CompanySeeder::class,
            ChartOfAccountsSeeder::class,
            FiscalYearSeeder::class,
            DocumentSequenceSeeder::class,
            TaxSeeder::class,
            RoleAndPermissionSeeder::class,
            DemoUserSeeder::class,
        ]);
    }
}
