<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A forecast is derived on demand, so nothing here is proved by writing: the
// point of these tests is that the actual column is the posted ledger and the
// forecast column is either the plan or a run-rate, labelled as whichever it
// is, and that the two never mix. The fixtures that post to the ledger are
// therefore raw inserts, for the same reason the cash flow and budget tests use
// them: which lines count as posted is decided by the triggers.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A fiscal period of the caller's company, installed directly so a test can
 * give the current month a past to average and a future to run into. The
 * fixture already installs March (period 3); this is how January, February and
 * the months after March are added.
 */
function nxForecastInstallPeriod(
    int $companyId,
    int $fiscalYearId,
    int $periodNo,
    string $name,
    string $startsOn,
    string $endsOn,
): int {
    return DB::table('fiscal_periods')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'period_no' => $periodNo,
        'name' => $name,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Write a two-line journal onto one period and post it. The date is written to
 * the journal and to both lines, because a trigger refuses a line that
 * disagrees with its journal's posting date, and the period has to be the one
 * the date falls in for the ledger to accept it.
 */
function nxForecastPost(
    int $companyId,
    int $fiscalYearId,
    int $fiscalPeriodId,
    string $number,
    string $date,
    int $debitAccountId,
    int $creditAccountId,
    string $amount,
): void {
    $journalId = DB::table('journals')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'fiscal_period_id' => $fiscalPeriodId,
        'journal_no' => $number,
        'journal_type' => 'general',
        'status' => 'draft',
        'posting_date' => $date,
        'currency_code' => 'TZS',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([[1, $debitAccountId, $amount, '0'], [2, $creditAccountId, '0', $amount]] as $line) {
        DB::table('journal_lines')->insert([
            'company_id' => $companyId,
            'journal_id' => $journalId,
            'line_no' => $line[0],
            'account_id' => $line[1],
            'currency_code' => 'TZS',
            'posting_date' => $date,
            'debit' => $line[2],
            'credit' => $line[3],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('journals')->where('id', $journalId)->update([
        'status' => 'posted',
        'posted_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The plan for one period, written straight to the tables. A forecast reads the
 * plan through the same model the budget route writes, so seeding it here keeps
 * the test about the forecast rather than about the write route beside it.
 *
 * @param  list<array{account_id: int, amount: string}>  $lines
 */
function nxForecastPlan(int $companyId, int $fiscalPeriodId, array $lines): int
{
    $budgetId = DB::table('budgets')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'fiscal_period_id' => $fiscalPeriodId,
        'name' => 'Plan',
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($lines as $line) {
        DB::table('budget_lines')->insert([
            'company_id' => $companyId,
            'budget_id' => $budgetId,
            'account_id' => $line['account_id'],
            'cost_center_id' => null,
            'amount' => $line['amount'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $budgetId;
}

/**
 * A member of a company, holding exactly one company-scoped role. A null role
 * leaves them a member with no rights at all.
 */
function nxForecastMember(int $companyId, ?string $role = null): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    if ($role !== null) {
        setPermissionsTeamId($companyId);
        $user->syncRoles([$role]);
        setPermissionsTeamId(null);
    }

    return $user;
}

/**
 * A second tenant with a calendar of its own, used to prove a forecast cannot
 * be pointed at books the caller is not a member of.
 *
 * @return array{company_id: int, period_ulid: string}
 */
function nxForecastForeignCompany(): array
{
    $now = now();

    $companyId = DB::table('companies')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'name' => 'Nexas Other Company Limited',
        'country_code' => 'TZ',
        'base_currency_code' => 'TZS',
        'fiscal_year_start_month' => 1,
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $fiscalYearId = DB::table('fiscal_years')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'name' => 'FY2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $periodUlid = (string) Str::ulid();

    DB::table('fiscal_periods')->insert([
        'ulid' => $periodUlid,
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'period_no' => 3,
        'name' => 'March 2026',
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['company_id' => $companyId, 'period_ulid' => $periodUlid];
}

/**
 * The three months a run-rate needs: January and February behind the current
 * period, and April ahead of it. The identifiers are passed in rather than read
 * off the test case, because a file-scope function cannot reach the trait's
 * protected properties.
 *
 * @return array{jan: int, feb: int, apr: int}
 */
function nxForecastQuarter(int $companyId, int $fiscalYearId): array
{
    return [
        'jan' => nxForecastInstallPeriod($companyId, $fiscalYearId, 1, 'January 2026', '2026-01-01', '2026-01-31'),
        'feb' => nxForecastInstallPeriod($companyId, $fiscalYearId, 2, 'February 2026', '2026-02-01', '2026-02-28'),
        'apr' => nxForecastInstallPeriod($companyId, $fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30'),
    ];
}

/*
|--------------------------------------------------------------------------
| Basis: plan where written, run-rate where not
|--------------------------------------------------------------------------
*/

it('projects a period at the plan the company wrote for it', function () {
    // January and February are behind us, April is ahead; March is current.
    nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]);

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.projection.data.current_period.name', 'March 2026')
        // March is projected at the plan to the cent, and each row says so
        // rather than leaving a reader to guess where the number came from.
        ->assertJsonPath('sections.projection.data.periods.2.period.name', 'March 2026')
        ->assertJsonPath('sections.projection.data.periods.2.is_forecast', true)
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.code', '4000')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.source', 'budget')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '600.0000')
        ->assertJsonPath('sections.projection.data.periods.2.expense.accounts.0.code', '5000')
        ->assertJsonPath('sections.projection.data.periods.2.expense.accounts.0.source', 'budget')
        ->assertJsonPath('sections.projection.data.periods.2.expense.accounts.0.forecast', '150.0000')
        ->assertJsonPath('sections.projection.data.periods.2.net.forecast', '450.0000');
});

it('projects a period with no plan at the trailing run-rate', function () {
    $periods = nxForecastQuarter($this->companyId, $this->fiscalYearId);

    // January earned 300 and February 100, so the two completed periods
    // average 200 of revenue a month.
    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['jan'], 'JV-100001', '2026-01-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');
    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['feb'], 'JV-100002', '2026-02-15', $this->receivableAccountId, $this->revenueAccountId, '100.0000');

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.projection.data.run_rate.used', true)
        ->assertJsonPath('sections.projection.data.run_rate.period_count', 2)
        ->assertJsonPath('sections.projection.data.run_rate.from', '2026-01-01')
        ->assertJsonPath('sections.projection.data.run_rate.to', '2026-02-28')
        // March wrote no plan, so it is projected at the average of what the
        // account actually did — and the row says run_rate rather than budget.
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.source', 'run_rate')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '200.0000')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.actual', '0.0000')
        // April has nothing behind it but the same run-rate.
        ->assertJsonPath('sections.projection.data.periods.3.revenue.accounts.0.source', 'run_rate')
        ->assertJsonPath('sections.projection.data.periods.3.revenue.accounts.0.forecast', '200.0000');
});

it('lets the plan win over the run-rate for the same account', function () {
    $periods = nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['jan'], 'JV-100001', '2026-01-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');
    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['feb'], 'JV-100002', '2026-02-15', $this->receivableAccountId, $this->revenueAccountId, '100.0000');

    nxForecastPlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '640.0000'],
    ]);

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        // The plan is 640, the run-rate is 200: the plan is used where it was
        // written, and the average only fills the gap beside it.
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.source', 'budget')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '640.0000');
});

it('keeps a plan that says zero rather than falling through to the run-rate', function () {
    $periods = nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['jan'], 'JV-100001', '2026-01-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');
    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['feb'], 'JV-100002', '2026-02-15', $this->receivableAccountId, $this->revenueAccountId, '100.0000');

    // A little revenue did land in March; the plan says none more is expected.
    nxForecastPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100003', '2026-03-10', $this->receivableAccountId, $this->revenueAccountId, '50.0000');

    nxForecastPlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '0'],
    ]);

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        // A plan of nothing is a plan: the account is not handed the run-rate's
        // 200 behind the company's back.
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.source', 'budget')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '0.0000')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.actual', '50.0000');
});

/*
|--------------------------------------------------------------------------
| Labels and assumptions
|--------------------------------------------------------------------------
*/

it('says which basis each figure came from and what it assumed', function () {
    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        // Said on the payload itself, because a reader of this response is one
        // step away from the ledger and should not mistake a projection for a
        // fact.
        ->assertJsonPath('sections.projection.data.forecast_is_not_actual', true)
        ->assertJsonPath('sections.projection.data.basis', 'budget where the company wrote a plan for the period, otherwise the trailing run-rate')
        ->assertJsonPath('sections.projection.data.run_rate.basis', 'average per period of what the account actually posted')
        ->assertJsonCount(6, 'sections.projection.data.assumptions');

    expect($response->json('sections.projection.data.note'))->toBeString()->toContain('not a transaction')
        ->and($response->json('sections.projection.data.assumptions.0'))->toContain('plan');
});

it('reports the periods already past without projecting onto them', function () {
    $periods = nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['jan'], 'JV-100001', '2026-01-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        // January is reported as it happened: its actual is the ledger and its
        // forecast column is null rather than a zero that would read as a
        // forecast of nothing.
        ->assertJsonPath('sections.projection.data.periods.0.period.name', 'January 2026')
        ->assertJsonPath('sections.projection.data.periods.0.is_forecast', false)
        ->assertJsonPath('sections.projection.data.periods.0.revenue.accounts.0.actual', '300.0000')
        ->assertJsonPath('sections.projection.data.periods.0.revenue.accounts.0.forecast', null)
        ->assertJsonPath('sections.projection.data.periods.0.revenue.forecast', null)
        ->assertJsonPath('sections.projection.data.periods.0.net.forecast', null);
});

/*
|--------------------------------------------------------------------------
| Horizon
|--------------------------------------------------------------------------
*/

it('runs to the last period of the open year and no further', function () {
    nxForecastInstallPeriod($this->companyId, $this->fiscalYearId, 1, 'January 2026', '2026-01-01', '2026-01-31');
    nxForecastInstallPeriod($this->companyId, $this->fiscalYearId, 2, 'February 2026', '2026-02-01', '2026-02-28');
    nxForecastInstallPeriod($this->companyId, $this->fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30');
    nxForecastInstallPeriod($this->companyId, $this->fiscalYearId, 5, 'May 2026', '2026-05-01', '2026-05-31');
    nxForecastInstallPeriod($this->companyId, $this->fiscalYearId, 6, 'June 2026', '2026-06-01', '2026-06-30');

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        // March through June: four periods, ending on the last day of June, the
        // last period the company has opened. Nothing beyond it is invented.
        ->assertJsonPath('sections.projection.data.horizon.from_period_name', 'March 2026')
        ->assertJsonPath('sections.projection.data.horizon.to_period_name', 'June 2026')
        ->assertJsonPath('sections.projection.data.horizon.period_count', 4)
        ->assertJsonPath('sections.projection.data.horizon.ends_on', '2026-06-30')
        // The whole year is reported, so the payload holds six periods and the
        // last of them is June — no period outside the calendar appears.
        ->assertJsonCount(6, 'sections.projection.data.periods')
        ->assertJsonPath('sections.projection.data.periods.5.period.name', 'June 2026');
});

/*
|--------------------------------------------------------------------------
| The calendar cannot answer
|--------------------------------------------------------------------------
*/

it('refuses a forecast when the fiscal year is not open', function () {
    DB::table('fiscal_years')->where('id', $this->fiscalYearId)->update(['status' => 'closed']);

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertStatus(409)
        ->assertJsonPath('error', 'ForecastUnavailableException');

    expect($response->json('message'))->toContain('no open year to forecast from');
});

it('refuses a forecast for a company with no calendar and for a date no period covers', function () {
    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // A date outside every period the company has opened.
    $this->getJson('/api/v1/reports/forecast?as_of=2025-12-15')
        ->assertStatus(409)
        ->assertJsonPath('error', 'ForecastUnavailableException');

    // A company with no periods at all.
    DB::table('fiscal_periods')->where('company_id', $this->companyId)->delete();

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertStatus(409)
        ->assertJsonPath('error', 'ForecastUnavailableException');
});

/*
|--------------------------------------------------------------------------
| The actual column is the ledger
|--------------------------------------------------------------------------
*/

it('reads the actual column straight from the posted ledger', function () {
    $periods = nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['jan'], 'JV-100001', '2026-01-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');
    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['feb'], 'JV-100002', '2026-02-15', $this->receivableAccountId, $this->revenueAccountId, '100.0000');

    // What March actually did: 500 of revenue and 200 of expense.
    nxForecastPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100003', '2026-03-15', $this->receivableAccountId, $this->revenueAccountId, '500.0000');
    nxForecastPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100004', '2026-03-15', $this->expenseAccountId, $this->cashAccountId, '200.0000');

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.projection.data.periods.2.revenue.actual', '500.0000')
        ->assertJsonPath('sections.projection.data.periods.2.expense.actual', '200.0000')
        ->assertJsonPath('sections.projection.data.periods.2.net.actual', '300.0000');

    // The same window read through the profit and loss statement: the forecast's
    // actual column and the statement are the same ledger.
    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('revenue.total', '500.0000')
        ->assertJsonPath('expense.total', '200.0000')
        ->assertJsonPath('net_profit', '300.0000');
});

it('keeps the forecast out of the actual column and out of the statements', function () {
    nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]);

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        // The plan is projected, but the actual column beside it is the ledger,
        // which is empty for March.
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '600.0000')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.actual', '0.0000');

    // A report built from the ledger cannot see the plan at all.
    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('revenue.total', '0.0000')
        ->assertJsonPath('expense.total', '0.0000')
        ->assertJsonPath('net_profit', '0.0000');
});

/*
|--------------------------------------------------------------------------
| Computed on demand
|--------------------------------------------------------------------------
*/

it('stores nothing and posts nothing when a forecast is read', function () {
    $periods = nxForecastQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPost($this->companyId, $this->fiscalYearId, $periods['jan'], 'JV-100001', '2026-01-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');

    nxForecastPlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]);

    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $counts = [
        'journals' => DB::table('journals')->count(),
        'journal_lines' => DB::table('journal_lines')->count(),
        'budgets' => DB::table('budgets')->count(),
        'budget_lines' => DB::table('budget_lines')->count(),
        'audit_logs' => DB::table('audit_logs')->count(),
    ];

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertOk();

    // A forecast is a read: it writes no row and posts no journal, so every
    // count is where it was.
    foreach ($counts as $table => $expected) {
        expect(DB::table($table)->count())->toBe($expected);
    }
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the forecast without a token', function () {
    $this->getJson('/api/v1/reports/forecast')->assertUnauthorized();
});

it('guards the forecast with the plan right rather than the report right', function () {
    // The Read-only role reads every statement the books have to give and is
    // still not shown what the company intends.
    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_READ_ONLY));

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')->assertOk();
    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertForbidden();

    // A Cashier holds no plan right either.
    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertForbidden();

    // The Auditor reads the plan to check it, and may therefore read the
    // forecast built on it.
    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertOk();
});

it('refuses the forecast for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxForecastMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreign = nxForecastForeignCompany();

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});
