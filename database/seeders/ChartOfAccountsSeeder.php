<?php

namespace Database\Seeders;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Seeder;

/**
 * A default chart of accounts for a Tanzanian trading company.
 *
 * Structure notes:
 *  - The tree is declarative. `account_type` is inherited from the root node
 *    unless a node overrides it, and `normal_balance` is derived from the type
 *    unless a node overrides it, which is how contra accounts (accumulated
 *    depreciation, sales discounts, drawings) are expressed.
 *  - Header nodes set `postable => false`. Nothing may be posted to a roll-up.
 *  - `path` holds the ancestor codes only, so 1110 under 1100 under 1000 has
 *    path "1000/1100" and depth 2.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $tree = [
        [
            'code' => '1000',
            'name' => 'Assets',
            'type' => 'asset',
            'postable' => false,
            'children' => [
                [
                    'code' => '1100',
                    'name' => 'Current Assets',
                    'postable' => false,
                    'children' => [
                        ['code' => '1110', 'name' => 'Cash on Hand', 'cash' => true],
                        ['code' => '1120', 'name' => 'Bank - CRDB', 'bank' => true],
                        ['code' => '1130', 'name' => 'Bank - NMB', 'bank' => true],
                        ['code' => '1140', 'name' => 'Petty Cash', 'cash' => true],
                        ['code' => '1200', 'name' => 'Accounts Receivable'],
                        ['code' => '1210', 'name' => 'Allowance for Doubtful Debts', 'normal' => 'credit', 'description' => 'Contra asset'],
                        ['code' => '1300', 'name' => 'Inventory'],
                        ['code' => '1310', 'name' => 'Prepaid Expenses'],
                        ['code' => '1400', 'name' => 'VAT Receivable (Input VAT)'],
                    ],
                ],
                [
                    'code' => '1500',
                    'name' => 'Non-Current Assets',
                    'postable' => false,
                    'children' => [
                        ['code' => '1510', 'name' => 'Land and Buildings'],
                        ['code' => '1520', 'name' => 'Plant and Machinery'],
                        ['code' => '1530', 'name' => 'Motor Vehicles'],
                        ['code' => '1540', 'name' => 'Furniture and Fixtures'],
                        ['code' => '1550', 'name' => 'Office Equipment'],
                        ['code' => '1590', 'name' => 'Accumulated Depreciation', 'normal' => 'credit', 'description' => 'Contra asset'],
                    ],
                ],
            ],
        ],
        [
            'code' => '2000',
            'name' => 'Liabilities',
            'type' => 'liability',
            'postable' => false,
            'children' => [
                [
                    'code' => '2100',
                    'name' => 'Current Liabilities',
                    'postable' => false,
                    'children' => [
                        ['code' => '2110', 'name' => 'Accounts Payable'],
                        ['code' => '2120', 'name' => 'VAT Payable (Output VAT)'],
                        ['code' => '2130', 'name' => 'Accrued Expenses'],
                        ['code' => '2140', 'name' => 'Withholding Tax Payable'],
                        ['code' => '2150', 'name' => 'Salaries Payable'],
                        ['code' => '2160', 'name' => 'PAYE Payable'],
                        ['code' => '2170', 'name' => 'NSSF Payable'],
                        ['code' => '2180', 'name' => 'Skills Development Levy Payable'],
                        ['code' => '2200', 'name' => 'Customer Deposits'],
                    ],
                ],
                [
                    'code' => '2500',
                    'name' => 'Non-Current Liabilities',
                    'postable' => false,
                    'children' => [
                        ['code' => '2510', 'name' => 'Long-Term Loans'],
                    ],
                ],
            ],
        ],
        [
            'code' => '3000',
            'name' => 'Equity',
            'type' => 'equity',
            'postable' => false,
            'children' => [
                ['code' => '3100', 'name' => 'Share Capital'],
                ['code' => '3200', 'name' => 'Retained Earnings'],
                ['code' => '3300', 'name' => 'Owner\'s Drawings', 'normal' => 'debit'],
                ['code' => '3400', 'name' => 'Revaluation Reserve'],
            ],
        ],
        [
            'code' => '4000',
            'name' => 'Revenue',
            'type' => 'revenue',
            'postable' => false,
            'children' => [
                ['code' => '4100', 'name' => 'Sales Revenue'],
                ['code' => '4110', 'name' => 'Service Revenue'],
                ['code' => '4120', 'name' => 'Sales Discounts', 'normal' => 'debit', 'description' => 'Contra revenue'],
                ['code' => '4200', 'name' => 'Other Income'],
                ['code' => '4210', 'name' => 'Interest Income'],
                ['code' => '4220', 'name' => 'Foreign Exchange Gain'],
            ],
        ],
        [
            'code' => '5000',
            'name' => 'Cost of Sales',
            'type' => 'expense',
            'postable' => false,
            'children' => [
                ['code' => '5100', 'name' => 'Cost of Goods Sold'],
                ['code' => '5110', 'name' => 'Purchases'],
                ['code' => '5120', 'name' => 'Purchase Discounts', 'normal' => 'credit', 'description' => 'Contra expense'],
                ['code' => '5130', 'name' => 'Freight and Carriage Inward'],
            ],
        ],
        [
            'code' => '6000',
            'name' => 'Operating Expenses',
            'type' => 'expense',
            'postable' => false,
            'children' => [
                ['code' => '6100', 'name' => 'Salaries and Wages'],
                ['code' => '6110', 'name' => 'Staff Welfare'],
                ['code' => '6120', 'name' => 'Employer NSSF Contribution'],
                ['code' => '6130', 'name' => 'Skills Development Levy'],
                ['code' => '6200', 'name' => 'Rent Expense'],
                ['code' => '6210', 'name' => 'Utilities'],
                ['code' => '6220', 'name' => 'Telephone and Internet'],
                ['code' => '6230', 'name' => 'Office Supplies'],
                ['code' => '6240', 'name' => 'Repairs and Maintenance'],
                ['code' => '6250', 'name' => 'Fuel and Lubricants'],
                ['code' => '6260', 'name' => 'Travel and Accommodation'],
                ['code' => '6270', 'name' => 'Professional Fees'],
                ['code' => '6280', 'name' => 'Audit Fees'],
                ['code' => '6290', 'name' => 'Bank Charges'],
                ['code' => '6300', 'name' => 'Insurance Expense'],
                ['code' => '6310', 'name' => 'Advertising and Marketing'],
                ['code' => '6320', 'name' => 'Licences and Permits'],
                ['code' => '6330', 'name' => 'Depreciation Expense'],
                ['code' => '6340', 'name' => 'Bad Debts Expense'],
                ['code' => '6350', 'name' => 'Foreign Exchange Loss'],
                ['code' => '6900', 'name' => 'Sundry Expenses'],
            ],
        ],
    ];

    public function run(): void
    {
        $company = Company::query()
            ->where('name', CompanySeeder::DEMO_COMPANY_NAME)
            ->firstOrFail();

        $this->seedNodes($company, $this->tree, null, [], 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, string>  $ancestorCodes
     */
    private function seedNodes(Company $company, array $nodes, ?Account $parent, array $ancestorCodes, int $depth): void
    {
        foreach ($nodes as $node) {
            $type = AccountType::from($node['type'] ?? $parent?->account_type->value ?? 'asset');
            $normal = NormalBalance::from($node['normal'] ?? $type->normalBalance()->value);

            $account = Account::updateOrCreate(
                ['company_id' => $company->id, 'code' => $node['code']],
                [
                    'name' => $node['name'],
                    'account_type' => $type,
                    'normal_balance' => $normal,
                    'parent_id' => $parent?->id,
                    'path' => $ancestorCodes === [] ? null : implode('/', $ancestorCodes),
                    'depth' => $depth,
                    'is_postable' => $node['postable'] ?? true,
                    'is_cash' => $node['cash'] ?? false,
                    'is_bank' => $node['bank'] ?? false,
                    'currency_code' => null,
                    'description' => $node['description'] ?? null,
                    'status' => 'active',
                ]
            );

            if (! empty($node['children'])) {
                $this->seedNodes($company, $node['children'], $account, [...$ancestorCodes, $node['code']], $depth + 1);
            }
        }
    }
}
