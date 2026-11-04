<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Actions\BudgetVersionStore;
use App\Models\FiscalYear;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A budget version has a life, and every step of it is proved over a real
// schema: the immutability the steps rely on is enforced by triggers beside
// budget_version_lines, not by the service that writes it, so a test that
// watched only the PHP would not notice the triggers going missing. Nothing
// here touches the ledger — the point of the module is that a plan, however far
// it is taken, posts no journal — and the count of journals is asserted rather
// than assumed.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * The publicly addressed ULID of a fiscal year.
 */
function nxLifeYearUlid(int $fiscalYearId): string
{
    return (string) DB::table('fiscal_years')->where('id', $fiscalYearId)->value('ulid');
}

/**
 * The publicly addressed ULID of a fiscal period.
 */
function nxLifePeriodUlid(int $fiscalPeriodId): string
{
    return (string) DB::table('fiscal_periods')->where('id', $fiscalPeriodId)->value('ulid');
}

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxLifeMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * The body the version write route expects, for the fixture's own year.
 *
 * @param  list<array{account_id: int, cost_center_id?: int|null, amount: string}>  $lines
 * @return array<string, mixed>
 */
function nxLifeVersionBody(int $fiscalYearId, int $fiscalPeriodId, array $lines): array
{
    $period = nxLifePeriodUlid($fiscalPeriodId);

    return [
        'fiscal_year' => nxLifeYearUlid($fiscalYearId),
        'name' => 'FY2026 Plan',
        'lines' => array_map(
            static fn (array $line): array => [
                'fiscal_period' => $period,
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'amount' => $line['amount'],
            ],
            $lines,
        ),
    ];
}

/**
 * One attempt at a decision, carrying the key that makes it replayable.
 *
 * The key is passed per request rather than set with withHeader(), because a
 * default header survives to the next request in the same test and a later
 * request would then carry a key it never meant to name.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse
 */
function nxLifeDecision(string $method, string $uri, array $body, string $key)
{
    return test()->json($method, $uri, $body, ['Idempotency-Key' => $key]);
}

/**
 * The live plan's lines for a period, as the reports read them.
 *
 * @return list<array{account_id: int, cost_center_id: int|null, amount: string}>
 */
function nxLifeLivePlan(int $companyId, int $fiscalPeriodId): array
{
    $budgetId = DB::table('budgets')
        ->where('company_id', $companyId)
        ->where('fiscal_period_id', $fiscalPeriodId)
        ->value('id');

    if ($budgetId === null) {
        return [];
    }

    $rows = DB::table('budget_lines')
        ->where('budget_id', $budgetId)
        ->orderBy('account_id')
        ->get();

    return $rows->map(static fn ($row): array => [
        'account_id' => (int) $row->account_id,
        'cost_center_id' => $row->cost_center_id === null ? null : (int) $row->cost_center_id,
        'amount' => (string) $row->amount,
    ])->all();
}

/**
 * A version's own lines for a period, in the same shape as the live plan, so
 * the two can be compared exactly.
 *
 * @return list<array{account_id: int, cost_center_id: int|null, amount: string}>
 */
function nxLifeVersionPlan(string $versionUlid, int $fiscalPeriodId): array
{
    $versionId = DB::table('budget_versions')->where('ulid', $versionUlid)->value('id');

    $rows = DB::table('budget_version_lines')
        ->where('budget_version_id', $versionId)
        ->where('fiscal_period_id', $fiscalPeriodId)
        ->orderBy('account_id')
        ->get();

    return $rows->map(static fn ($row): array => [
        'account_id' => (int) $row->account_id,
        'cost_center_id' => $row->cost_center_id === null ? null : (int) $row->cost_center_id,
        'amount' => (string) $row->amount,
    ])->all();
}

/*
|--------------------------------------------------------------------------
| The life of a plan
|--------------------------------------------------------------------------
*/

it('walks a plan from draft to raised to approved to locked and back', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $owner = nxLifeMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($manager);

    $created = $this->putJson('/api/v1/budgets/versions', nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '12000.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '4000.0000'],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.version_no', 1)
        ->assertJsonPath('data.is_a_plan', true)
        ->json('data');

    $ulid = $created['ulid'];

    // Raised. The plan leaves the manager's hands and waits in the queue.
    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending_approval');

    // The plan waits in the same queue a journal or a supplier bill does.
    $queued = DB::table('approval_requests')
        ->where('company_id', $this->companyId)
        ->where('document_type', 'budget')
        ->first();

    expect($queued)->not->toBeNull()
        ->and($queued->status)->toBe('pending')
        ->and($queued->requested_by_user_id)->toBe($manager->getKey());

    // The manager wrote and raised it, so the manager may not approve it.
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'life-approve-self')
        ->assertStatus(409)
        ->assertJsonPath('error', 'ApprovalStateException');

    // The Owner holds budget.approve and does not hold approval.decide, and can
    // still agree to a plan — through the door the plan opens for it.
    Sanctum::actingAs($owner);

    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'life-approve-owner')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // A separate act from approving it: closing the plan.
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/lock", [], 'life-lock')
        ->assertOk()
        ->assertJsonPath('data.status', 'locked');

    // The history the panel reads says the whole story.
    $this->getJson('/api/v1/budgets/versions?fiscal_year='.nxLifeYearUlid($this->fiscalYearId))
        ->assertOk()
        ->assertJsonPath('data.0.version_no', 1)
        ->assertJsonPath('data.0.status', 'locked')
        ->assertJsonPath('data.0.is_editable', false);

    // Reopening undoes the close, not the decision the plan carries.
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/unlock", [], 'life-unlock')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

it('supersedes a plan that has been agreed to with the next version, leaving the first alone', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $owner = nxLifeMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($manager);

    $first = $this->putJson('/api/v1/budgets/versions', nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '12000.0000'],
    ]))->assertCreated()->json('data');

    // The plan is raised and agreed to, so its amounts are frozen where they are.
    $this->postJson("/api/v1/budgets/versions/{$first['ulid']}/submit")->assertOk();

    Sanctum::actingAs($owner);

    nxLifeDecision('POST', "/api/v1/budgets/versions/{$first['ulid']}/approve", [], 'next-approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // A change to an agreed plan is the next version, and it has to say why.
    Sanctum::actingAs($manager);

    $this->putJson('/api/v1/budgets/versions', nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '15000.0000'],
    ]))
        ->assertStatus(409)
        ->assertJsonPath('error', 'BudgetStateException');

    $second = $this->putJson('/api/v1/budgets/versions', [
        'fiscal_year' => nxLifeYearUlid($this->fiscalYearId),
        'name' => 'FY2026 Plan (revised)',
        'reason' => 'The board revised the revenue target for the year.',
        'lines' => [[
            'fiscal_period' => nxLifePeriodUlid($this->fiscalPeriodId),
            'account_id' => $this->revenueAccountId,
            'amount' => '15000.0000',
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.version_no', 2)
        ->assertJsonPath('data.status', 'draft')
        ->json('data');

    // The superseded version is still there, still approved, and still says what
    // it said: the history is append-only.
    $this->getJson("/api/v1/budgets/versions/{$first['ulid']}")
        ->assertOk()
        ->assertJsonPath('data.version_no', 1)
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.lines.0.amount', '12000.0000');

    // The live plan the reports read is now the second version, not a blend of
    // the two.
    expect(nxLifeLivePlan($this->companyId, $this->fiscalPeriodId))
        ->toBe([[
            'account_id' => $this->revenueAccountId,
            'cost_center_id' => null,
            'amount' => '15000.0000',
        ]]);
});

it('records every move of a plan in the audit chain', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $owner = nxLifeMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($manager);

    $ulid = $this->putJson('/api/v1/budgets/versions', nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '900.0000'],
    ]))->assertCreated()->json('data.ulid');

    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")->assertOk();

    Sanctum::actingAs($owner);

    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'audit-approve')->assertOk();

    $actions = DB::table('audit_logs')
        ->where('company_id', $this->companyId)
        ->pluck('action')
        ->all();

    expect($actions)->toContain('budget.version_saved')
        ->and($actions)->toContain('budget.submitted')
        ->and($actions)->toContain('approval.approved')
        ->and($actions)->toContain('budget.approved');
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses a second member the approval the first raised it for', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    $ulid = $this->putJson('/api/v1/budgets/versions', nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '500.0000'],
    ]))->assertCreated()->json('data.ulid');

    // A decision with nothing waiting on it is a conflict, not a bad request.
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'refuse-not-pending')
        ->assertStatus(409)
        ->assertJsonPath('error', 'BudgetStateException');

    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")->assertOk();

    // Raising it twice is refused: it is already waiting.
    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")
        ->assertStatus(409)
        ->assertJsonPath('error', 'BudgetStateException');
});

it('refuses to edit a plan that has been raised or locked', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $owner = nxLifeMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($manager);

    $body = nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '700.0000'],
    ]);

    $ulid = $this->putJson('/api/v1/budgets/versions', $body)->assertCreated()->json('data.ulid');

    // A draft is edited in place, so this is a rewrite rather than a new version.
    $this->putJson('/api/v1/budgets/versions', $body)
        ->assertOk()
        ->assertJsonPath('data.version_no', 1);

    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")->assertOk();

    // Raised: a change now is the next version, not an edit of this one.
    $this->putJson('/api/v1/budgets/versions', $body)
        ->assertStatus(409)
        ->assertJsonPath('error', 'BudgetStateException');

    Sanctum::actingAs($owner);

    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'edit-approve')->assertOk();
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/lock", [], 'edit-lock')->assertOk();

    // Locked: no further version may be started from it either.
    $this->putJson('/api/v1/budgets/versions', $body)
        ->assertStatus(409)
        ->assertJsonPath('error', 'BudgetStateException');
});

it('refuses a plan raised for one year to be pointed at another year\u2019s period', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    $otherYearId = DB::table('fiscal_years')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'name' => 'FY2027',
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->putJson('/api/v1/budgets/versions', [
        'fiscal_year' => nxLifeYearUlid($otherYearId),
        // A period of FY2026, offered for a plan about FY2027.
        'lines' => [[
            'fiscal_period' => nxLifePeriodUlid($this->fiscalPeriodId),
            'account_id' => $this->revenueAccountId,
            'amount' => '100.0000',
        ]],
    ])->assertStatus(422);
});

it('answers not found for a version of another company', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    $this->getJson('/api/v1/budgets/versions/'.(string) Str::ulid())->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Who may move a plan
|--------------------------------------------------------------------------
*/

it('keeps the Accountant and every other reader out of a plan\u2019s life', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    $body = nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '300.0000'],
    ]);

    $ulid = $this->putJson('/api/v1/budgets/versions', $body)->assertCreated()->json('data.ulid');

    // The Accountant reads the plan and no longer writes it.
    Sanctum::actingAs(nxLifeMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/budgets/versions?fiscal_year='.nxLifeYearUlid($this->fiscalYearId))->assertOk();
    $this->getJson("/api/v1/budgets/versions/{$ulid}")->assertOk();
    $this->putJson('/api/v1/budgets/versions', $body)->assertForbidden();
    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")->assertForbidden();
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'accountant-approve')->assertForbidden();
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/lock", [], 'accountant-lock')->assertForbidden();

    // The Read-only role is not shown the plan at all.
    Sanctum::actingAs(nxLifeMember($this->companyId, AccessMatrix::ROLE_READ_ONLY));

    $this->getJson('/api/v1/budgets/versions?fiscal_year='.nxLifeYearUlid($this->fiscalYearId))->assertForbidden();
    $this->getJson("/api/v1/budgets/versions/{$ulid}")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The live plan equals the live version
|--------------------------------------------------------------------------
*/

it('keeps the live plan equal to the version on the table', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    $body = nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '12000.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '4000.0000'],
    ]);

    $ulid = $this->putJson('/api/v1/budgets/versions', $body)->assertCreated()->json('data.ulid');

    $expected = [
        ['account_id' => $this->revenueAccountId, 'cost_center_id' => null, 'amount' => '12000.0000'],
        ['account_id' => $this->expenseAccountId, 'cost_center_id' => null, 'amount' => '4000.0000'],
    ];

    expect(nxLifeVersionPlan($ulid, $this->fiscalPeriodId))->toBe($expected)
        ->and(nxLifeLivePlan($this->companyId, $this->fiscalPeriodId))->toBe($expected);

    // Rewriting the draft moves the live plan with it, still as version one.
    $rewritten = nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '20000.0000'],
    ]);

    $this->putJson('/api/v1/budgets/versions', $rewritten)
        ->assertOk()
        ->assertJsonPath('data.version_no', 1);

    $afterRewrite = [
        ['account_id' => $this->revenueAccountId, 'cost_center_id' => null, 'amount' => '20000.0000'],
    ];

    expect(nxLifeVersionPlan($ulid, $this->fiscalPeriodId))->toBe($afterRewrite)
        ->and(nxLifeLivePlan($this->companyId, $this->fiscalPeriodId))->toBe($afterRewrite);

    // One row each: the live plan and the version on the table, nothing more.
    expect(DB::table('budgets')->where('company_id', $this->companyId)->count())->toBe(1)
        ->and(DB::table('budget_versions')->where('company_id', $this->companyId)->count())->toBe(1);
});

it('changes nothing when the write of a version fails part way', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $owner = nxLifeMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($manager);

    $good = nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '8000.0000'],
    ]);

    $ulid = $this->putJson('/api/v1/budgets/versions', $good)->assertCreated()->json('data.ulid');

    $liveBefore = nxLifeLivePlan($this->companyId, $this->fiscalPeriodId);
    $versionBefore = nxLifeVersionPlan($ulid, $this->fiscalPeriodId);

    // Two lines on the same period and account, with no cost centre, collapse
    // onto the same row of the table's grain and are refused by its unique
    // index — a real failure part way through a write.
    $store = app(BudgetVersionStore::class);
    $year = FiscalYear::query()->whereKey($this->fiscalYearId)->firstOrFail();

    $threw = false;

    try {
        $store->save($this->companyId, $manager->getKey(), $year, [
            ['fiscal_period_id' => $this->fiscalPeriodId, 'account_id' => $this->revenueAccountId, 'cost_center_id' => null, 'amount' => '1.0000'],
            ['fiscal_period_id' => $this->fiscalPeriodId, 'account_id' => $this->revenueAccountId, 'cost_center_id' => null, 'amount' => '2.0000'],
        ]);
    } catch (Throwable $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    // The failure rolled back with it: the version, its lines and the live plan
    // are exactly what they were before the attempt.
    expect(DB::table('budget_versions')->where('company_id', $this->companyId)->count())->toBe(1)
        ->and(nxLifeVersionPlan($ulid, $this->fiscalPeriodId))->toBe($versionBefore)
        ->and(nxLifeLivePlan($this->companyId, $this->fiscalPeriodId))->toBe($liveBefore);

    // And the plan still walks its life afterwards, so the rollback left nothing
    // half-written behind it.
    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")->assertOk();

    Sanctum::actingAs($owner);

    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'rollback-approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
});

/*
|--------------------------------------------------------------------------
| A budget is never a posting
|--------------------------------------------------------------------------
*/

it('never posts a journal, however far a plan is taken', function () {
    $manager = nxLifeMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $owner = nxLifeMember($this->companyId, AccessMatrix::ROLE_OWNER);

    $journalsBefore = DB::table('journals')->count();
    $linesBefore = DB::table('journal_lines')->count();

    Sanctum::actingAs($manager);

    $body = nxLifeVersionBody($this->fiscalYearId, $this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '15000.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '5000.0000'],
    ]);

    $ulid = $this->putJson('/api/v1/budgets/versions', $body)->assertCreated()->json('data.ulid');

    $this->putJson('/api/v1/budgets/versions', $body)->assertOk();
    $this->postJson("/api/v1/budgets/versions/{$ulid}/submit")->assertOk();

    Sanctum::actingAs($owner);

    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/approve", [], 'nojournal-approve')->assertOk();
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/lock", [], 'nojournal-lock')->assertOk();
    nxLifeDecision('POST', "/api/v1/budgets/versions/{$ulid}/unlock", [], 'nojournal-unlock')->assertOk();

    expect(DB::table('journals')->count())->toBe($journalsBefore)
        ->and(DB::table('journal_lines')->count())->toBe($linesBefore)
        ->and(DB::table('journals')->count())->toBe(0);

    // The plan is still on the books as a plan, and the ledger is untouched.
    expect(nxLifeLivePlan($this->companyId, $this->fiscalPeriodId))->toHaveCount(2);
});
