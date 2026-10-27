<?php

namespace Database\Seeders;

use App\Domain\Accounting\Enums\TaxAppliesTo;
use App\Domain\Accounting\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Tanzanian tax rules expressed as data.
 *
 * A tax code carries no rate of its own; each rate is a dated row in
 * tax_rates. Changing a rate is therefore an insert, not a code change, and a
 * document issued last year keeps the rate that was in force on its own date.
 * The rates below are the effective windows the demo company starts with.
 */
class TaxSeeder extends Seeder
{
    /**
     * @var array<int, array{
     *     code: string,
     *     name: string,
     *     tax_type: string,
     *     applies_to: string,
     *     is_inclusive: bool,
     *     is_recoverable: bool,
     *     output_account: ?string,
     *     input_account: ?string,
     *     rates: array<int, array{rate: string, effective_from: string}>
     * }>
     */
    private array $taxCodes = [
        [
            'code' => 'VAT18',
            'name' => 'VAT 18% (Standard Rated)',
            'tax_type' => 'vat',
            'applies_to' => 'both',
            'is_inclusive' => false,
            'is_recoverable' => true,
            'output_account' => '2120',
            'input_account' => '1400',
            'rates' => [
                ['rate' => '0.180000', 'effective_from' => '2015-07-01'],
            ],
        ],
        [
            'code' => 'VATZ',
            'name' => 'VAT Zero-Rated',
            'tax_type' => 'vat',
            'applies_to' => 'both',
            'is_inclusive' => false,
            'is_recoverable' => true,
            'output_account' => '2120',
            'input_account' => '1400',
            'rates' => [
                ['rate' => '0.000000', 'effective_from' => '2015-07-01'],
            ],
        ],
        [
            'code' => 'VATE',
            'name' => 'VAT Exempt',
            'tax_type' => 'vat',
            'applies_to' => 'both',
            'is_inclusive' => false,
            'is_recoverable' => false,
            'output_account' => '2120',
            'input_account' => null,
            'rates' => [
                ['rate' => '0.000000', 'effective_from' => '2015-07-01'],
            ],
        ],
        [
            'code' => 'WHT10',
            'name' => 'Withholding Tax 10% (Resident Services)',
            'tax_type' => 'withholding',
            'applies_to' => 'purchases',
            'is_inclusive' => false,
            'is_recoverable' => false,
            'output_account' => '2140',
            'input_account' => null,
            'rates' => [
                ['rate' => '0.100000', 'effective_from' => '2016-07-01'],
            ],
        ],
    ];

    public function run(): void
    {
        $company = Company::query()
            ->where('name', CompanySeeder::DEMO_COMPANY_NAME)
            ->firstOrFail();

        foreach ($this->taxCodes as $definition) {
            $taxCode = TaxCode::updateOrCreate(
                ['company_id' => $company->id, 'code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'tax_type' => TaxType::from($definition['tax_type']),
                    'applies_to' => TaxAppliesTo::from($definition['applies_to']),
                    'is_inclusive' => $definition['is_inclusive'],
                    'is_recoverable' => $definition['is_recoverable'],
                    'output_account_id' => $this->accountId($company, $definition['output_account']),
                    'input_account_id' => $this->accountId($company, $definition['input_account']),
                    'status' => 'active',
                ]
            );

            foreach ($definition['rates'] as $rate) {
                TaxRate::updateOrCreate(
                    [
                        'company_id' => $company->id,
                        'tax_code_id' => $taxCode->id,
                        'effective_from' => $rate['effective_from'],
                    ],
                    [
                        'rate' => $rate['rate'],
                        'effective_to' => null,
                    ]
                );
            }
        }
    }

    private function accountId(Company $company, ?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return Account::query()
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->value('id');
    }
}
