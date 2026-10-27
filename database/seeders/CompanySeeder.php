<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * The demo tenant plus its default branch.
 *
 * Other seeders resolve this company by name, so the name is exposed as a
 * constant rather than repeated as a literal.
 */
class CompanySeeder extends Seeder
{
    public const DEMO_COMPANY_NAME = 'Nexas Demo Company Limited';

    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => self::DEMO_COMPANY_NAME],
            [
                'legal_name' => 'Nexas Demo Company Limited',
                'tin' => '123-456-789',
                'vrn' => '10-123456-A',
                'country_code' => 'TZ',
                'base_currency_code' => 'TZS',
                'fiscal_year_start_month' => 1,
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
                'settings' => [
                    'inventory_costing' => 'weighted_average',
                    'allow_negative_stock' => false,
                ],
            ]
        );

        Branch::updateOrCreate(
            ['company_id' => $company->id, 'code' => 'HQ'],
            [
                'name' => 'Head Office',
                'address' => 'Dar es Salaam, Tanzania',
                'phone' => '+255 22 000 0000',
                'is_default' => true,
                'status' => 'active',
            ]
        );
    }
}
