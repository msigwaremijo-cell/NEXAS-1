<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * One financial year divided into its twelve monthly periods.
 *
 * The year starts in January because the demo company sets
 * fiscal_year_start_month = 1; a company starting in July would get periods
 * whose months follow its own start. Every period begins open, and period_no
 * stays inside the 1..12 range the database CHECK enforces.
 */
class FiscalYearSeeder extends Seeder
{
    private const YEAR = 2026;

    public function run(): void
    {
        $company = Company::query()
            ->where('name', CompanySeeder::DEMO_COMPANY_NAME)
            ->firstOrFail();

        $startMonth = $company->fiscal_year_start_month;
        $startsOn = CarbonImmutable::create(self::YEAR, $startMonth, 1)->startOfMonth();
        $endsOn = $startsOn->addMonths(11)->endOfMonth();

        $fiscalYear = FiscalYear::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'FY '.self::YEAR],
            [
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'status' => 'open',
            ]
        );

        for ($periodNo = 1; $periodNo <= 12; $periodNo++) {
            $monthStart = $startsOn->addMonths($periodNo - 1)->startOfMonth();
            $monthEnd = $monthStart->endOfMonth();

            FiscalPeriod::updateOrCreate(
                [
                    'company_id' => $company->id,
                    'fiscal_year_id' => $fiscalYear->id,
                    'period_no' => $periodNo,
                ],
                [
                    'name' => $monthStart->format('F Y'),
                    'starts_on' => $monthStart->toDateString(),
                    'ends_on' => $monthEnd->toDateString(),
                    'status' => 'open',
                    'closed_at' => null,
                    'closed_by_user_id' => null,
                ]
            );
        }
    }
}
