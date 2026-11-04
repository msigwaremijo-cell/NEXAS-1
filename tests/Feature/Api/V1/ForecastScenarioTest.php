<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Services\CashFlowQuery;
use App\Domain\Reporting\Services\CashRunway;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Saved forecast scenarios are assumptions and nothing else. These tests are
// about the two claims that make them safe to keep at all: that a saved
// scenario never becomes a ledger figure — no journal, no actual report, no
// move in the books — and that its history cannot be rewritten. The projection
// underneath is proved by ForecastTest; the composition of the page by
// ForecastPageTest. What is proved here is the store, the history, the runway
// arithmetic and who is allowed to reach any of it.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company holding exactly one company-scoped role.
 */
function nxScenarioMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A fiscal period of the caller's company, installed directly so a test can
 * give the current month a past to average over.
 */
function nxScenarioPeriod(
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
 * January and February behind the fixture's March, so the run-rate has two
 * completed periods to average over and the history label has something to
 * count.
 */
function nxScenarioHistory(int $companyId, int $fiscalYearId): void
{
    nxScenarioPeriod($companyId, $fiscalYearId, 1, 'January 2026', '2026-01-01', '2026-01-31');
    nxScenarioPeriod($companyId, $fiscalYearId, 2, 'February 2026', '2026-02-01', '2026-02-28');
}

/**
 * The plan for one period, written straight to the tables the budget route
 * writes, so the test stays about the forecast rather than the write beside it.
 *
 * @param  list<array{account_id: int, amount: string}>  $lines
 */
function nxScenarioPlan(int $companyId, int $fiscalPeriodId, array $lines): void
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
}

/**
 * A scenario belonging to a company the caller is not a member of, installed
 * straight into the tables so the API cannot have produced it on the caller's
 * behalf.
 *
 * @return array{company_id: int, scenario_ulid: string, version_ulid: string}
 */
function nxScenarioForeign(): array
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

    $scenarioUlid = (string) Str::ulid();

    $scenarioId = DB::table('forecast_scenarios')->insertGetId([
        'ulid' => $scenarioUlid,
        'company_id' => $companyId,
        'name' => 'Their Case',
        'type' => 'base',
        'current_version_no' => 1,
        'created_by_user_id' => null,
        'row_version' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $versionUlid = (string) Str::ulid();

    DB::table('forecast_scenario_versions')->insert([
        'ulid' => $versionUlid,
        'company_id' => $companyId,
        'forecast_scenario_id' => $scenarioId,
        'version_no' => 1,
        'type' => 'base',
        'revenue_growth' => '0.0000',
        'expense_growth' => '0.0000',
        'adjustments' => null,
        'note' => null,
        'created_by_user_id' => null,
        'created_at' => $now,
    ]);

    return ['company_id' => $companyId, 'scenario_ulid' => $scenarioUlid, 'version_ulid' => $versionUlid];
}

/**
 * A projection of the shape ForecastQuery returns, holding only the fields the
 * runway reads, so a runway figure can be asserted against fixed sample data
 * rather than against a forecast that a plan or a ledger row happens to produce.
 *
 * @param  array<string, string>  $netByMonth  period name => projected net movement
 * @return array<string, mixed>
 */
function nxScenarioProjection(array $netByMonth, string $asOf = '2026-03-15'): array
{
    $periods = [];
    $periodNo = 0;

    foreach ($netByMonth as $name => $net) {
        $periodNo++;

        $periods[] = [
            'period' => ['name' => (string) $name, 'period_no' => $periodNo],
            'is_forecast' => true,
            'has_plan' => false,
            'budget' => ['revenue' => null, 'expense' => null, 'net' => null],
            'revenue' => ['accounts' => [], 'actual' => '0.0000', 'forecast' => '0.0000'],
            'expense' => ['accounts' => [], 'actual' => '0.0000', 'forecast' => '0.0000'],
            'net' => ['actual' => '0.0000', 'forecast' => (string) $net],
        ];
    }

    return [
        'as_of' => $asOf,
        'periods' => $periods,
        'run_rate' => [
            'used' => true,
            'basis' => 'average per period of what the account actually posted',
            'from' => null,
            'to' => null,
            'period_count' => count($periods),
            'periods' => [],
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| Saving, versioning and history
|--------------------------------------------------------------------------
*/

it('saves a new scenario with its first version', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $response = $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'optimistic',
        'revenue_growth' => '10',
        'expense_growth' => '4',
        'adjustments' => [
            ['account_id' => $this->revenueAccountId, 'amount' => '250'],
        ],
        'note' => 'The case put to the board',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Board Case')
        ->assertJsonPath('data.type', 'optimistic')
        ->assertJsonPath('data.type_label', 'Optimistic')
        ->assertJsonPath('data.current_version_no', 1)
        ->assertJsonPath('data.version_count', 1)
        ->assertJsonPath('data.current_version.version_no', 1)
        ->assertJsonPath('data.current_version.revenue_growth', '10.0000')
        ->assertJsonPath('data.current_version.expense_growth', '4.0000')
        ->assertJsonPath('data.current_version.adjustments.'.$this->revenueAccountId, '250.0000');

    $ulid = (string) $response->json('data.ulid');

    $this->assertDatabaseHas('forecast_scenarios', [
        'ulid' => $ulid,
        'company_id' => $this->companyId,
        'name' => 'Board Case',
        'type' => 'optimistic',
        'current_version_no' => 1,
    ]);

    // The version is a separate, write-once row: the identity and the
    // assumptions are never the same row.
    $this->assertDatabaseHas('forecast_scenario_versions', [
        'company_id' => $this->companyId,
        'version_no' => 1,
        'type' => 'optimistic',
        'revenue_growth' => '10.0000',
    ]);
});

it('changes assumptions by writing the next version rather than editing one', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'optimistic',
        'revenue_growth' => '10',
        'expense_growth' => '4',
    ])->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", [
        'type' => 'pessimistic',
        'revenue_growth' => '-5',
        'expense_growth' => '2',
    ])
        ->assertCreated()
        ->assertJsonPath('data.version_no', 2)
        ->assertJsonPath('data.type', 'pessimistic')
        ->assertJsonPath('data.revenue_growth', '-5.0000');

    // The pointer moves, and the first version is still exactly what it was.
    $this->assertDatabaseHas('forecast_scenarios', [
        'ulid' => $ulid,
        'current_version_no' => 2,
        'type' => 'pessimistic',
    ]);
    $this->assertDatabaseHas('forecast_scenario_versions', [
        'version_no' => 1,
        'type' => 'optimistic',
        'revenue_growth' => '10.0000',
    ]);
    $this->assertDatabaseHas('forecast_scenario_versions', [
        'version_no' => 2,
        'type' => 'pessimistic',
        'revenue_growth' => '-5.0000',
    ]);
});

it('keeps every previous version and shows the newest first', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", [
        'type' => 'optimistic',
        'revenue_growth' => '12',
    ])->assertCreated();

    $this->getJson("/api/v1/forecast/scenarios/{$ulid}")
        ->assertOk()
        ->assertJsonPath('data.version_count', 2)
        ->assertJsonPath('data.current_version_no', 2)
        ->assertJsonPath('data.versions.0.version_no', 2)
        ->assertJsonPath('data.versions.0.revenue_growth', '12.0000')
        ->assertJsonPath('data.versions.1.version_no', 1)
        ->assertJsonPath('data.versions.1.revenue_growth', '0.0000')
        // A saved scenario is assumptions, and opening it restates them
        // against the ledger as it stands today.
        ->assertJsonPath('data.recalculated_with_current_actuals', true);
});

it('records the author and timestamp of every version', function () {
    $first = nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $second = nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($first);

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated()->json('data.ulid');

    Sanctum::actingAs($second);

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", [
        'type' => 'optimistic',
        'revenue_growth' => '8',
    ])->assertCreated();

    $this->assertDatabaseHas('forecast_scenarios', [
        'ulid' => $ulid,
        'created_by_user_id' => $first->getKey(),
    ]);
    $this->assertDatabaseHas('forecast_scenario_versions', [
        'version_no' => 1,
        'created_by_user_id' => $first->getKey(),
    ]);
    $this->assertDatabaseHas('forecast_scenario_versions', [
        'version_no' => 2,
        'created_by_user_id' => $second->getKey(),
    ]);

    $detail = $this->getJson("/api/v1/forecast/scenarios/{$ulid}")->assertOk();

    // Who wrote what, and when: the newest version is the second hand's.
    $detail->assertJsonPath('data.created_by.id', $first->getKey())
        ->assertJsonPath('data.versions.0.created_by.id', $second->getKey())
        ->assertJsonPath('data.versions.1.created_by.id', $first->getKey());

    expect($detail->json('data.versions.0.created_at'))->toBeString()->not->toBe('')
        ->and($detail->json('data.versions.1.created_at'))->toBeString()->not->toBe('');
});

it('writes an audit entry for every save, revision and restore', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated()->json('data.ulid');

    $scenarioId = (int) DB::table('forecast_scenarios')->where('ulid', $ulid)->value('id');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", [
        'type' => 'optimistic',
        'revenue_growth' => '15',
    ])->assertCreated();

    $firstVersionUlid = (string) DB::table('forecast_scenario_versions')
        ->where('forecast_scenario_id', $scenarioId)
        ->where('version_no', 1)
        ->value('ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions/{$firstVersionUlid}/restore")
        ->assertCreated();

    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'forecast_scenario.created',
        'auditable_id' => $scenarioId,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'forecast_scenario.revised',
        'auditable_id' => $scenarioId,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'forecast_scenario.restored',
        'auditable_id' => $scenarioId,
    ]);

    // The entry says which version was written and what it was written from,
    // rather than only that something happened.
    $revised = DB::table('audit_logs')
        ->where('action', 'forecast_scenario.revised')
        ->orderByDesc('id')
        ->first();

    expect(json_decode((string) $revised->after, true)['version_no'])->toBe(2);

    $restored = DB::table('audit_logs')
        ->where('action', 'forecast_scenario.restored')
        ->orderByDesc('id')
        ->first();

    expect(json_decode((string) $restored->after, true)['restored_from_version_no'])->toBe(1);
});

it('restores an old version by writing a further version', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'optimistic',
        'revenue_growth' => '10',
    ])->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", [
        'type' => 'pessimistic',
        'revenue_growth' => '-5',
    ])->assertCreated();

    $firstVersionUlid = (string) DB::table('forecast_scenario_versions')
        ->join('forecast_scenarios', 'forecast_scenarios.id', '=', 'forecast_scenario_versions.forecast_scenario_id')
        ->where('forecast_scenarios.ulid', $ulid)
        ->where('version_no', 1)
        ->value('forecast_scenario_versions.ulid');

    // Restoring writes version three that copies version one. Version one is
    // read, never consumed, and stays exactly where it was.
    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions/{$firstVersionUlid}/restore", [
        'note' => 'Back to the board case',
    ])
        ->assertCreated()
        ->assertJsonPath('data.version_no', 3)
        ->assertJsonPath('data.type', 'optimistic')
        ->assertJsonPath('data.revenue_growth', '10.0000')
        ->assertJsonPath('data.note', 'Back to the board case');

    $this->assertDatabaseHas('forecast_scenarios', [
        'ulid' => $ulid,
        'current_version_no' => 3,
        'type' => 'optimistic',
    ]);
    $this->assertDatabaseHas('forecast_scenario_versions', ['version_no' => 1, 'type' => 'optimistic', 'revenue_growth' => '10.0000']);
    $this->assertDatabaseHas('forecast_scenario_versions', ['version_no' => 2, 'type' => 'pessimistic', 'revenue_growth' => '-5.0000']);
    $this->assertDatabaseHas('forecast_scenario_versions', ['version_no' => 3, 'type' => 'optimistic', 'revenue_growth' => '10.0000']);
});

it('compares saved scenarios on assumptions only', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $one = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Case One',
        'type' => 'optimistic',
        'revenue_growth' => '10',
    ])->assertCreated()->json('data.ulid');

    $two = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Case Two',
        'type' => 'pessimistic',
        'revenue_growth' => '-5',
    ])->assertCreated()->json('data.ulid');

    $this->getJson('/api/v1/forecast/scenarios/comparison?scenarios[]='.$one.'&scenarios[]='.$two)
        ->assertOk()
        ->assertJsonCount(2, 'data.scenarios')
        ->assertJsonPath('data.scenarios.0.name', 'Case One')
        ->assertJsonPath('data.scenarios.0.current_version.revenue_growth', '10.0000')
        ->assertJsonPath('data.scenarios.1.name', 'Case Two')
        ->assertJsonPath('data.scenarios.1.current_version.revenue_growth', '-5.0000');
});

/*
|--------------------------------------------------------------------------
| Immutability and numbering
|--------------------------------------------------------------------------
*/

it('refuses to update or delete a version in the database', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated();

    $versionId = (int) DB::table('forecast_scenario_versions')->orderBy('id')->value('id');

    // Not a policy and not a controller rule: the database itself refuses, so
    // no future code path can quietly rewrite a saved assumption.
    expect(fn () => DB::table('forecast_scenario_versions')->where('id', $versionId)->update(['revenue_growth' => '99.0000']))
        ->toThrow(QueryException::class, 'immutable');

    expect(fn () => DB::table('forecast_scenario_versions')->where('id', $versionId)->delete())
        ->toThrow(QueryException::class, 'cannot be deleted');

    $this->assertDatabaseHas('forecast_scenario_versions', ['id' => $versionId, 'revenue_growth' => '0.0000']);
});

it('numbers versions sequentially and refuses a duplicate number', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", ['type' => 'base', 'revenue_growth' => '1'])->assertCreated();
    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", ['type' => 'base', 'revenue_growth' => '2'])->assertCreated();

    $scenarioId = (int) DB::table('forecast_scenarios')->where('ulid', $ulid)->value('id');

    $numbers = DB::table('forecast_scenario_versions')
        ->where('forecast_scenario_id', $scenarioId)
        ->orderBy('version_no')
        ->pluck('version_no')
        ->all();

    expect($numbers)->toBe([1, 2, 3]);

    // Two versions of one scenario cannot share a number, so the history can
    // never hold two rows that both claim to be the same state.
    expect(fn () => DB::table('forecast_scenario_versions')->insert([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'forecast_scenario_id' => $scenarioId,
        'version_no' => 2,
        'type' => 'base',
        'revenue_growth' => '0.0000',
        'expense_growth' => '0.0000',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('refuses a version filed under the wrong company', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated()->json('data.ulid');

    $scenarioId = (int) DB::table('forecast_scenarios')->where('ulid', $ulid)->value('id');

    $foreign = nxScenarioForeign();

    expect(fn () => DB::table('forecast_scenario_versions')->insert([
        'ulid' => (string) Str::ulid(),
        'company_id' => $foreign['company_id'],
        'forecast_scenario_id' => $scenarioId,
        'version_no' => 2,
        'type' => 'base',
        'revenue_growth' => '0.0000',
        'expense_growth' => '0.0000',
        'created_at' => now(),
    ]))->toThrow(QueryException::class, 'must match its scenario');
});

/*
|--------------------------------------------------------------------------
| Authorisation and tenants
|--------------------------------------------------------------------------
*/

it('gives the scenario endpoints to the roles that hold the scenario right', function () {
    foreach ([AccessMatrix::ROLE_OWNER, AccessMatrix::ROLE_FINANCIAL_MANAGER] as $role) {
        Sanctum::actingAs(nxScenarioMember($this->companyId, $role));

        $this->getJson('/api/v1/forecast/scenarios')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->postJson('/api/v1/forecast/scenarios', [
            'name' => 'Case for '.$role,
            'type' => 'base',
            'revenue_growth' => '0',
        ])->assertCreated();
    }
});

it('refuses every scenario endpoint to a caller without the right', function () {
    // One scenario exists, created by somebody entitled to create it, so the
    // row-scoped routes reach their policy rather than failing on a 404.
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'base',
        'revenue_growth' => '0',
    ])->assertCreated()->json('data.ulid');

    $versionUlid = (string) DB::table('forecast_scenario_versions')->orderBy('id')->value('ulid');

    foreach ([
        AccessMatrix::ROLE_ACCOUNTANT,
        AccessMatrix::ROLE_CASHIER,
        AccessMatrix::ROLE_AUDITOR,
        AccessMatrix::ROLE_READ_ONLY,
    ] as $role) {
        Sanctum::actingAs(nxScenarioMember($this->companyId, $role));

        $this->getJson('/api/v1/forecast/scenarios')->assertForbidden();
        $this->postJson('/api/v1/forecast/scenarios', ['name' => 'No', 'type' => 'base'])->assertForbidden();
        $this->getJson("/api/v1/forecast/scenarios/{$ulid}")->assertForbidden();
        $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", ['type' => 'base'])->assertForbidden();
        $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions/{$versionUlid}/restore")->assertForbidden();
        $this->getJson('/api/v1/forecast/scenarios/comparison?scenarios[]='.$ulid)->assertForbidden();
    }
});

it('refuses a scenario belonging to another company', function () {
    $foreign = nxScenarioForeign();

    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The route binds by ULID across every company, so the policy is what
    // refuses a scenario from books the caller is not a member of.
    $this->getJson("/api/v1/forecast/scenarios/{$foreign['scenario_ulid']}")->assertForbidden();
    $this->postJson("/api/v1/forecast/scenarios/{$foreign['scenario_ulid']}/versions", ['type' => 'base'])->assertForbidden();
    $this->postJson("/api/v1/forecast/scenarios/{$foreign['scenario_ulid']}/versions/{$foreign['version_ulid']}/restore")->assertForbidden();

    // And the listing shows none of it.
    $this->getJson('/api/v1/forecast/scenarios')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/*
|--------------------------------------------------------------------------
| Nothing here reaches the ledger
|--------------------------------------------------------------------------
*/

it('posts no journal and moves no ledger figure when scenarios are saved', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    // A posted receipt, so there is a ledger that could move.
    $this->postJournal($this->balancedJournal('J-1', '500.0000'));

    $journals = DB::table('journals')->count();
    $lines = DB::table('journal_lines')->count();

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'optimistic',
        'revenue_growth' => '25',
        'adjustments' => [['account_id' => $this->revenueAccountId, 'amount' => '1000']],
    ])->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", ['type' => 'pessimistic', 'revenue_growth' => '-20'])->assertCreated();

    $versionUlid = (string) DB::table('forecast_scenario_versions')->orderBy('id')->value('ulid');
    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions/{$versionUlid}/restore")->assertCreated();

    expect(DB::table('journals')->count())->toBe($journals)
        ->and(DB::table('journal_lines')->count())->toBe($lines);

    // The ledger's own answer is unmoved too: a scenario is a stance, and a
    // stance does not add money to a bank account.
    $position = app(CashFlowQuery::class)->position($this->companyId, '2026-03-15');

    expect($position['total'])->toBe('500.0000');
});

it('leaves the actual reports untouched', function () {
    $this->postJournal($this->balancedJournal('J-1', '500.0000'));

    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $before = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json();

    $ulid = (string) $this->postJson('/api/v1/forecast/scenarios', [
        'name' => 'Board Case',
        'type' => 'optimistic',
        'revenue_growth' => '30',
        'expense_growth' => '10',
        'adjustments' => [['account_id' => $this->revenueAccountId, 'amount' => '5000']],
    ])->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/forecast/scenarios/{$ulid}/versions", ['type' => 'pessimistic', 'revenue_growth' => '-30'])->assertCreated();

    $after = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json();

    // Not "similar figures" — the same report, to the byte. A saved forecast
    // version cannot appear in an actual statement because it is not in the
    // ledger the statement reads.
    expect($after)->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Cash runway
|--------------------------------------------------------------------------
*/

it('reads the runway from the cash position and the projected movement', function () {
    // 10,000 in the bank and four projected months at -2,000 each: five months
    // of cover, and the cash never turns negative inside the horizon.
    $this->postJournal($this->balancedJournal('J-1', '10000.0000'));

    $runway = app(CashRunway::class)->analyse($this->companyId, nxScenarioProjection([
        'April 2026' => '-2000.0000',
        'May 2026' => '-2000.0000',
        'June 2026' => '-2000.0000',
        'July 2026' => '-2000.0000',
    ]), '0');

    expect($runway['is_estimate'])->toBeTrue()
        ->and($runway['has_cash_data'])->toBeTrue()
        ->and($runway['opening_cash'])->toBe('10000.0000')
        ->and($runway['average_monthly_movement'])->toBe('-2000.0000')
        ->and($runway['average_monthly_burn'])->toBe('2000.0000')
        ->and($runway['is_burning'])->toBeTrue()
        ->and($runway['months_of_cover'])->toBe('5.0000')
        ->and($runway['negative_month'])->toBeNull()
        ->and($runway['no_negative_month'])->toBeTrue()
        ->and($runway['lowest_cash'])->toBe('2000.0000')
        ->and($runway['lowest_cash_month'])->toBe('July 2026')
        ->and($runway['horizon_months'])->toBe(4)
        ->and($runway['status'])->toBe('healthy');

    expect($runway['months'])->toHaveCount(4)
        ->and($runway['months'][3]['closing_cash'])->toBe('2000.0000')
        ->and($runway['months'][3]['negative'])->toBeFalse();
});

it('names the first month the cash turns negative', function () {
    $this->postJournal($this->balancedJournal('J-1', '1000.0000'));

    $runway = app(CashRunway::class)->analyse($this->companyId, nxScenarioProjection([
        'April 2026' => '-400.0000',
        'May 2026' => '-400.0000',
        'June 2026' => '-400.0000',
        'July 2026' => '-400.0000',
    ]), '0');

    // 1,000 less 400 a month: the third month is the first the closing cash
    // goes below zero, and that month and no other.
    expect($runway['negative_month'])->toBe('June 2026')
        ->and($runway['no_negative_month'])->toBeFalse()
        ->and($runway['months_of_cover'])->toBe('2.5000')
        ->and($runway['status'])->toBe('critical')
        ->and($runway['status_label'])->toBe('Cash is projected to run out');

    expect($runway['months'][0]['negative'])->toBeFalse()
        ->and($runway['months'][1]['negative'])->toBeFalse()
        ->and($runway['months'][2]['negative'])->toBeTrue()
        ->and($runway['months'][2]['closing_cash'])->toBe('-200.0000');
});

it('handles zero burn and positive cash generation without inventing a date', function () {
    $this->postJournal($this->balancedJournal('J-1', '5000.0000'));

    // Break-even: the movement is zero, so there is nothing to divide by and
    // no month the company runs out.
    $flat = app(CashRunway::class)->analyse($this->companyId, nxScenarioProjection([
        'April 2026' => '0.0000',
        'May 2026' => '0.0000',
        'June 2026' => '0.0000',
    ]), '0');

    expect($flat['is_burning'])->toBeFalse()
        ->and($flat['months_of_cover'])->toBeNull()
        ->and($flat['negative_month'])->toBeNull()
        ->and($flat['no_negative_month'])->toBeTrue()
        ->and($flat['status_label'])->toBe('Cash is not depleting');

    // Generating cash: the movement is positive, so the balance only grows.
    $growing = app(CashRunway::class)->analyse($this->companyId, nxScenarioProjection([
        'April 2026' => '1000.0000',
        'May 2026' => '1000.0000',
        'June 2026' => '1000.0000',
    ]), '0');

    expect($growing['is_burning'])->toBeFalse()
        ->and($growing['average_monthly_movement'])->toBe('1000.0000')
        ->and($growing['average_monthly_burn'])->toBe('0.0000')
        ->and($growing['months_of_cover'])->toBeNull()
        ->and($growing['negative_month'])->toBeNull()
        ->and($growing['lowest_cash'])->toBe('5000.0000')
        ->and($growing['lowest_cash_month'])->toBeNull();
});

it('reports an unknown position when the company has no cash accounts', function () {
    // A company with nothing flagged cash or bank has no position to read, so
    // the panel says so rather than answering zero and calling it healthy.
    $runway = app(CashRunway::class)->analyse($this->companyId + 5000, nxScenarioProjection([
        'April 2026' => '-1000.0000',
    ]), '0');

    expect($runway['has_cash_data'])->toBeFalse()
        ->and($runway['status'])->toBe('unknown')
        ->and($runway['status_label'])->toBe('Cash position unknown')
        ->and($runway['months_of_cover'])->toBeNull()
        ->and($runway['negative_month'])->toBeNull()
        ->and($runway['note'])->toContain('No cash or bank account');
});

it('changes the runway status with the low-cash threshold', function () {
    $this->postJournal($this->balancedJournal('J-1', '10000.0000'));

    $projection = nxScenarioProjection([
        'April 2026' => '-4500.0000',
        'May 2026' => '-4500.0000',
    ]);

    // Cash dips to 1,000 but never turns negative. Above the floor it is
    // healthy; at or below it the panel warns.
    $healthy = app(CashRunway::class)->analyse($this->companyId, $projection, '0');

    expect($healthy['status'])->toBe('healthy')
        ->and($healthy['lowest_cash'])->toBe('1000.0000');

    $watch = app(CashRunway::class)->analyse($this->companyId, $projection, '2000.0000');

    expect($watch['status'])->toBe('watch')
        ->and($watch['threshold'])->toBe('2000.0000')
        ->and($watch['months'][1]['below_threshold'])->toBeTrue();

    // A floor the company is already under is critical before any projection.
    $critical = app(CashRunway::class)->analyse($this->companyId, $projection, '12000.0000');

    expect($critical['status'])->toBe('critical');
});

/*
|--------------------------------------------------------------------------
| The low-cash threshold setting
|--------------------------------------------------------------------------
*/

it('sets the low-cash threshold by merging the company settings', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    // Something else is already in the blob, and it has to survive.
    DB::table('companies')->where('id', $this->companyId)->update([
        'settings' => json_encode(['something_else' => 'kept']),
        'updated_at' => now(),
    ]);

    $this->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => '7500'])
        ->assertOk()
        ->assertJsonPath('data.forecast_low_cash_threshold', '7500.0000');

    $settings = json_decode((string) DB::table('companies')->where('id', $this->companyId)->value('settings'), true);

    expect($settings['something_else'])->toBe('kept')
        ->and($settings['forecast_low_cash_threshold'])->toBe('7500.0000');

    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'company.forecast_settings.updated',
    ]);

    // Saving the same value again is not a change, so it is not a decision and
    // leaves no second entry.
    $this->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => '7500.0000'])->assertOk();

    expect(DB::table('audit_logs')->where('action', 'company.forecast_settings.updated')->count())->toBe(1);
});

it('refuses a negative or malformed threshold', function () {
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    foreach (['-100', 'abc', '1.234567', '1,000', ''] as $value) {
        $this->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('forecast_low_cash_threshold');
    }

    $settings = json_decode((string) DB::table('companies')->where('id', $this->companyId)->value('settings'), true) ?? [];

    expect($settings)->not->toHaveKey('forecast_low_cash_threshold');
});

it('gives the threshold endpoint only to the scenario right', function () {
    foreach ([
        AccessMatrix::ROLE_ACCOUNTANT,
        AccessMatrix::ROLE_CASHIER,
        AccessMatrix::ROLE_AUDITOR,
        AccessMatrix::ROLE_READ_ONLY,
    ] as $role) {
        Sanctum::actingAs(nxScenarioMember($this->companyId, $role));

        $this->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => '1000'])
            ->assertForbidden();
    }

    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => '1000'])->assertOk();
});

it('refuses the settings write for a company the caller does not belong to', function () {
    $foreign = nxScenarioForeign();

    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => '1000'])
        ->assertForbidden();

    // The foreign company's settings were not touched.
    expect(DB::table('companies')->where('id', $foreign['company_id'])->value('settings'))->toBeNull();
});

it('reads the company threshold into the runway panel', function () {
    nxScenarioHistory($this->companyId, $this->fiscalYearId);

    // March is planned to a loss the opening cash cannot cover, so the runway
    // alert fires, and the threshold in force is the company's own.
    nxScenarioPlan($this->companyId, $this->fiscalPeriodId, [
        ['account_id' => $this->expenseAccountId, 'amount' => '7000.0000'],
    ]);

    $this->postJournal($this->balancedJournal('J-1', '5000.0000'));

    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    // Without a setting the panel falls back to the deployment default.
    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.cash_runway.data.threshold_source', 'default')
        ->assertJsonPath('sections.cash_runway.data.threshold', '0.0000');

    $this->putJson('/api/v1/forecast/settings', ['forecast_low_cash_threshold' => '8000'])->assertOk();

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('sections.cash_runway.data.threshold_source', 'company')
        ->assertJsonPath('sections.cash_runway.data.threshold', '8000.0000')
        ->assertJsonPath('sections.cash_runway.data.opening_cash', '5000.0000')
        ->assertJsonPath('sections.cash_runway.data.negative_month', 'March 2026')
        ->assertJsonPath('sections.cash_runway.data.status', 'critical');
});

/*
|--------------------------------------------------------------------------
| Historical transparency
|--------------------------------------------------------------------------
*/

it('states how many months of history the projection rests on', function () {
    // Counting the run-rate's own record rather than a number held in code:
    // with no completed period behind the current one the label says zero.
    Sanctum::actingAs(nxScenarioMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('history.months', 0)
        ->assertJsonPath('history.label', 'Based on 0 months of historical data');

    // January and February behind March: two completed periods, and the label
    // moves with them.
    nxScenarioHistory($this->companyId, $this->fiscalYearId);

    $this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')
        ->assertOk()
        ->assertJsonPath('history.months', 2)
        ->assertJsonPath('history.label', 'Based on 2 months of historical data')
        ->assertJsonPath('history.available', true)
        ->assertJsonPath('history.periods', ['January 2026', 'February 2026']);

    // The limitations are stated rather than implied.
    expect($this->getJson('/api/v1/reports/forecast?as_of=2026-03-15')->json('history.limitations'))
        ->toBeArray()
        ->not->toBeEmpty();
});
