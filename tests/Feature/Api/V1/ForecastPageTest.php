<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The forecast page is one read that answers for a whole screen, so these tests
// are about the composition rather than about the projection itself: that every
// block is produced inside its own guard so one failing read cannot take the
// page down, that the scenario is the Financial Manager's own right and is
// refused on the presence of a parameter rather than on its value, and that the
// answer echoes back only what was validated. The projection underneath is
// proved by ForecastTest; nothing here re-derives an accounting figure.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A fiscal period of the caller's company, installed directly so a test can
 * give the current month a past and a future to run into.
 */
function nxForecastPageInstallPeriod(
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
 * The months a horizon needs: January and February behind the current period,
 * and April ahead of it. March is the fixture's own period.
 *
 * @return array{jan: int, feb: int, apr: int}
 */
function nxForecastPageQuarter(int $companyId, int $fiscalYearId): array
{
    return [
        'jan' => nxForecastPageInstallPeriod($companyId, $fiscalYearId, 1, 'January 2026', '2026-01-01', '2026-01-31'),
        'feb' => nxForecastPageInstallPeriod($companyId, $fiscalYearId, 2, 'February 2026', '2026-02-01', '2026-02-28'),
        'apr' => nxForecastPageInstallPeriod($companyId, $fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30'),
    ];
}

/**
 * The plan for one period, written straight to the tables the budget route
 * writes, so the test stays about the page rather than the write route beside
 * it.
 *
 * @param  list<array{account_id: int, amount: string}>  $lines
 */
function nxForecastPagePlan(int $companyId, int $fiscalPeriodId, array $lines): int
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
 * A member of a company holding exactly one company-scoped role.
 */
function nxForecastPageMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, used to prove the page cannot be pointed at books the caller
 * is not a member of.
 *
 * @return array{company_id: int, period_ulid: string}
 */
function nxForecastPageForeignCompany(): array
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

/*
|--------------------------------------------------------------------------
| The composition
|--------------------------------------------------------------------------
*/

it('answers the whole page in one read, block by block', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('company.id', $this->companyId)
        ->assertJsonPath('window.as_of', '2026-03-15')
        ->assertJsonPath('scenario.name', 'base')
        ->assertJsonPath('scenario.revenue_growth', '0')
        ->assertJsonPath('capabilities.scenario', false);

    // Every block on the page is an entry with its own data and its own error
    // slot, and every one of them was produced.
    foreach ([
        'projection',
        'kpis',
        'chart',
        'cash_outlook',
        'working_capital',
        'break_even',
        'budget_vs_forecast',
        'risk_alerts',
    ] as $key) {
        expect($response->json("sections.{$key}.data"))->not->toBeNull();
        expect($response->json("sections.{$key}.error"))->toBeNull();
    }

    // The scenario panel belongs to a reader who may run a scenario, so a page
    // read by somebody who may not holds no entry for it at all.
    expect($response->json('sections.scenario_comparison'))->toBeNull();
});

it('reads the projection from the service the report uses rather than a second copy', function () {
    nxForecastPageQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPagePlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]);

    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.projection.data.current_period.name', 'March 2026')
        ->assertJsonPath('sections.projection.data.horizon.from_period_name', 'March 2026')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.source', 'budget')
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '600.0000')
        ->assertJsonPath(
            'sections.projection.data.basis',
            'budget where the company wrote a plan for the period, otherwise the trailing run-rate',
        );
});

it('totals the kpi cards from the projection rather than beside it', function () {
    nxForecastPageQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPagePlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]);

    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertOk();

    $cards = collect($response->json('sections.kpis.data.cards'))->keyBy('key');
    $totals = $response->json('sections.projection.data.horizon_totals');

    // A card and the projection it summarises are the same figure said twice,
    // never two sums that could drift.
    expect($cards)->toHaveCount(6)
        ->and($cards['revenue']['forecast'])->toBe($totals['revenue']['forecast'])
        ->and($cards['expense']['forecast'])->toBe($totals['expense']['forecast'])
        ->and($cards['net_profit']['forecast'])->toBe($totals['net']['forecast'])
        // The plan the cards compare against is the plan, not the projection.
        ->and($cards['revenue']['budget'])->toBe('600.0000')
        ->and($cards['revenue']['variance'])->toBe('0.0000')
        ->and($cards['revenue']['direction'])->toBe('neutral');
});

it('says on the page that a projection is not an actual', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.projection.data.forecast_is_not_actual', true)
        // Every block that is a projection or an estimate says so itself,
        // rather than leaving a reader to infer it from the heading.
        ->assertJsonPath('sections.cash_outlook.data.is_estimate', true)
        ->assertJsonPath('sections.working_capital.data.is_estimate', true);

    expect($response->json('sections.projection.data.note'))->toBeString()->toContain('not a transaction');
});

it('states the assumptions the answer was produced under', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertOk();

    // The page records what it assumed rather than leaving it to be read out of
    // the numbers: the six the projection states, and a note on the cards.
    $response->assertJsonCount(6, 'sections.projection.data.assumptions');
    expect($response->json('sections.kpis.data.note'))->toBeString()->not->toBe('')
        ->and($response->json('sections.budget_vs_forecast.data.note'))->toBeString();
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('guards the page with the projection right rather than the report right', function () {
    // Both roles read every statement the books report, and neither is shown
    // what the company expects.
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_READ_ONLY));
    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertForbidden();

    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_CASHIER));
    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertForbidden();
});

it('gives the scenario panel to the scenario right and to nobody else', function () {
    // The Financial Manager may test the projection, so the comparison is
    // present and holds the three scenarios the company configured.
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('capabilities.scenario', true)
        ->assertJsonCount(3, 'sections.scenario_comparison.data.scenarios')
        ->assertJsonPath('sections.scenario_comparison.data.scenarios.0.name', 'base')
        ->assertJsonPath('sections.scenario_comparison.data.scenarios.0.selected', true);

    // The Accountant reads the projection and may not ask "what if", so the
    // panel is absent rather than empty.
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('capabilities.scenario', false)
        ->assertJsonPath('sections.scenario_comparison', null);
});

it('refuses any scenario parameter to a caller without the scenario right', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // Refused on the presence of a parameter and before it is parsed, so what
    // a caller unable to ask "what if" is sent is never read, validated and
    // discarded — it is never read at all. A growth of zero is still a
    // scenario the caller chose.
    foreach ([
        'scenario=optimistic',
        'scenario=base',
        'revenue_growth=10',
        'expense_growth=0',
        'adjustments[0][account_id]=1&adjustments[0][amount]=5',
    ] as $query) {
        $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15&'.$query)->assertForbidden();
    }
});

/*
|--------------------------------------------------------------------------
| Scenarios
|--------------------------------------------------------------------------
*/

it('restates the projection under a named scenario without rewriting the plan', function () {
    nxForecastPageQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPagePlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '1000.0000'],
    ]);

    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $base = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertOk();

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15&scenario=optimistic')
        ->assertOk()
        ->assertJsonPath('scenario.name', 'optimistic')
        ->assertJsonPath('scenario.revenue_growth', '10');

    // Optimistic is +10% revenue: 1000 becomes 1100 to the cent, and the plan
    // beside it is untouched, because a scenario does not rewrite what the
    // company said it meant to do.
    expect($base->json('sections.projection.data.periods.2.revenue.forecast'))->toBe('1000.0000')
        ->and($response->json('sections.projection.data.periods.2.revenue.forecast'))->toBe('1100.0000')
        ->and($response->json('sections.projection.data.periods.2.budget.revenue'))->toBe('1000.0000');
});

it('applies a named account adjustment on top of the scenario growth', function () {
    nxForecastPageQuarter($this->companyId, $this->fiscalYearId);

    nxForecastPagePlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '1000.0000'],
    ]);

    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // +10% takes the plan to 1100; the adjustment adds 50 more on top of it.
    $this->getJson(
        '/api/v1/reports/forecast?as_of=2026-03-15&scenario=optimistic'
        .'&adjustments[0][account_id]='.$this->revenueAccountId
        .'&adjustments[0][amount]=50'
    )
        ->assertOk()
        ->assertJsonPath('sections.projection.data.periods.2.revenue.accounts.0.forecast', '1150.0000')
        ->assertJsonPath('scenario.adjustments.'.$this->revenueAccountId, '50');
});

/*
|--------------------------------------------------------------------------
| What a scenario request is held to
|--------------------------------------------------------------------------
*/

it('refuses a growth rate outside the configured envelope', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The envelope is 1000% by default; beyond it, and anything that is not a
    // plain decimal literal, is refused before any arithmetic sees it.
    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15&revenue_growth=5000')
        ->assertStatus(422)
        ->assertJsonValidationErrors('revenue_growth');

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15&expense_growth=1e3')
        ->assertStatus(422)
        ->assertJsonValidationErrors('expense_growth');
});

it('refuses a scenario the company does not hold', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15&scenario=invented')
        ->assertStatus(422)
        ->assertJsonValidationErrors('scenario');
});

it('refuses more adjustments than the ceiling allows and a malformed amount', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The ceiling is 50 by default, so 51 is one too many.
    $adjustments = [];

    for ($index = 0; $index < 51; $index++) {
        $adjustments[$index] = ['account_id' => $index + 1, 'amount' => '1'];
    }

    $this->getJson('/api/v1/reports/forecast?'.http_build_query([
        'as_of' => '2026-03-15',
        'adjustments' => $adjustments,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('adjustments');

    // An amount that is not a plain decimal is refused rather than coerced.
    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15&adjustments[0][account_id]=1&adjustments[0][amount]=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors('adjustments.0.amount');
});

/*
|--------------------------------------------------------------------------
| One failing block leaves the page standing
|--------------------------------------------------------------------------
*/

it('answers a failing block on its own and leaves the rest of the page standing', function () {
    // One read the page makes is broken on purpose: the variance threshold the
    // risk block reads is not a number, so that block alone cannot be produced.
    // Every other block reads elsewhere and is unaffected.
    config(['nexas.forecast.variance_warning_percent' => 'not-a-number']);

    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->assertOk();

    $response->assertJsonPath('sections.risk_alerts.data', null);

    expect($response->json('sections.risk_alerts.error.type'))->toBe(InvalidArgumentException::class)
        ->and($response->json('sections.risk_alerts.error.message'))->toBeString()->not->toBe('');

    foreach ([
        'projection',
        'kpis',
        'chart',
        'cash_outlook',
        'working_capital',
        'break_even',
        'budget_vs_forecast',
        'scenario_comparison',
    ] as $key) {
        expect($response->json("sections.{$key}.error"))->toBeNull();
        expect($response->json("sections.{$key}.data"))->not->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| Roles and tenants
|--------------------------------------------------------------------------
*/

it('gives every role the page the matrix promises', function () {
    $grants = AccessMatrix::grants();

    foreach ($grants as $role => $held) {
        Sanctum::actingAs(nxForecastPageMember($this->companyId, $role));

        $response = $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15');

        if (! in_array(AccessMatrix::PERMISSION_FORECAST_VIEW, $held, true)) {
            $response->assertForbidden();

            continue;
        }

        $response->assertOk();

        // The panel follows the narrower right, not the wider one: a reader who
        // may see the projection is not thereby handed the scenario tests.
        if (in_array(AccessMatrix::PERMISSION_FORECAST_SCENARIO, $held, true)) {
            expect($response->json('sections.scenario_comparison'))->not->toBeNull();
        } else {
            expect($response->json('sections.scenario_comparison'))->toBeNull();
        }
    }
});

it('refuses the page for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxForecastPageMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreign = nxForecastPageForeignCompany();

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});
