<?php

namespace Database\Seeders;

use App\Domain\Accounting\Enums\DocumentType;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\FiscalYear;
use Illuminate\Database\Seeder;

/**
 * One gapless counter per document type for the seeded financial year.
 *
 * Nothing here allocates a number: this only creates the row PostJournal and
 * its siblings will lock with SELECT ... FOR UPDATE before incrementing
 * next_number.
 */
class DocumentSequenceSeeder extends Seeder
{
    /**
     * @var array<string, array{prefix: string, padding: int}>
     */
    private array $sequences = [
        DocumentType::Journal->value => ['prefix' => 'JV-', 'padding' => 6],
        DocumentType::Invoice->value => ['prefix' => 'INV-', 'padding' => 6],
        DocumentType::Bill->value => ['prefix' => 'BILL-', 'padding' => 6],
        DocumentType::Payment->value => ['prefix' => 'PAY-', 'padding' => 6],
        DocumentType::CreditNote->value => ['prefix' => 'CN-', 'padding' => 6],
    ];

    public function run(): void
    {
        $company = Company::query()
            ->where('name', CompanySeeder::DEMO_COMPANY_NAME)
            ->firstOrFail();

        $fiscalYear = FiscalYear::query()
            ->where('company_id', $company->id)
            ->orderByDesc('starts_on')
            ->firstOrFail();

        foreach ($this->sequences as $documentType => $config) {
            DocumentSequence::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'document_type' => $documentType,
                    'fiscal_year_id' => $fiscalYear->id,
                ],
                [
                    'prefix' => $config['prefix'],
                    'next_number' => 1,
                    'padding' => $config['padding'],
                ]
            );
        }
    }
}
