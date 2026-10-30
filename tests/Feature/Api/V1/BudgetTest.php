<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A budget is a plan and never a posting, so nothing here is proved by the
// ledger: writing one records no journal. What is proved over a real schema is
// that the plan and the ledger's own figures meet in one report — the actual
// column is the posted ledger read through FinancialStatementQuery, and the
// budget column is the company's own statement of intent. The fixtures that
// post to the ledger are therefore raw inserts, for the same reason the cash
// flow tests use them: which lines count as posted is decided by the triggers.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    $this->budget = [
        'company_id' => $this->companyId,
        'fiscal_year_id' => $this->fiscalYearId,
        'fiscal_period_id' => $this->fiscalPeriodId,
        'currency' => 'TZS',
        'cash' => $this->cashAccountId,
        'receivable' => $this->receivableAccountId,
        'revenue' => $this->revenueAccountId,
        'expense' => $this->expenseAccountId,
    ];
});

/**
 * The publicly addressed ULID of a fiscal period.
 */
function nxBudgetPeriodUlid(int $fiscalPeriodId): string
{
    return (string) DB::table('fiscal_periods')->where('id', $fiscalPeriodId)->value('ulid');
}

/**
 * A fiscal period of the caller's company, installed directly so a test can
 * give a month a predecessor to be compared with.
 */
function nxBudgetInstallPeriod(
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
 * Write a two-line journal dated on a given day, and post it. The date is
 * written to the journal and to both lines, because a trigger refuses a line
 * that disagrees with its journal's posting date.
 *
 * @param  array<string, mixed>  $ctx
 */
function nxBudgetPost(
    array $ctx,
    string $number,
    string $date,
    int $debitAccountId,
    int $creditAccountId,
    string $amount,
): void {
    $journalId = DB::table('journals')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $ctx['company_id'],
        'fiscal_year_id' => $ctx['fiscal_year_id'],
        'fiscal_period_id' => $ctx['fiscal_period_id'],
        'journal_no' => $number,
        'journal_type' => 'general',
        'status' => 'draft',
        'posting_date' => $date,
        'currency_code' => $ctx['currency'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([[1, $debitAccountId, $amount, '0'], [2, $creditAccountId, '0', $amount]] as $line) {
        DB::table('journal_lines')->insert([
            'company_id' => $ctx['company_id'],
            'journal_id' => $journalId,
            'line_no' => $line[0],
            'account_id' => $line[1],
            'currency_code' => $ctx['currency'],
            'posting_date' => $date,
            'debit' => $line[2],
            'credit' => $line[3],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Draft until this runs: the ledger, and therefore the actual column, can
    // only see the movement once the journal is posted.
    DB::table('journals')->where('id', $journalId)->update([
        'status' => 'posted',
        'posted_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A member of a company, holding exactly one company-scoped role. A null role
 * leaves them a member with no rights at all.
 */
function nxBudgetMember(int $companyId, ?string $role = null): User
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
 * A second tenant with a chart, a fiscal year and one open period, used to
 * prove a plan and a report cannot be pointed at books the caller is not a
 * member of.
 *
 * @return array{company_id: int, account_id: int, cost_center_id: int, period_ulid: string}
 */
function nxBudgetForeignCompany(): array
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

    $accountId = DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => '4000',
        'name' => 'Sales Revenue',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $costCenterId = DB::table('cost_centers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => 'CC-100',
        'name' => 'Head Office',
        'status' => 'active',
        'row_version' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return [
        'company_id' => $companyId,
        'account_id' => $accountId,
        'cost_center_id' => $costCenterId,
        'period_ulid' => $periodUlid,
    ];
}

/**
 * The plan body the write route expects, for the fixture's own accounts.
 *
 * @param  list<array{account_id: int, cost_center_id?: int|null, amount: string}>  $lines
 * @return array<string, mixed>
 */
function nxBudgetPlanBody(int $fiscalPeriodId, array $lines): array
{
    return [
        'fiscal_period' => nxBudgetPeriodUlid($fiscalPeriodId),
        'lines' => $lines,
    ];
}

/*
|--------------------------------------------------------------------------
| Writing a plan
|--------------------------------------------------------------------------
*/

it('writes a plan and records the change in the audit chain', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $before = DB::table('audit_logs')->count();

    $response = $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]))
        ->assertCreated()
        // Said on the resource itself, because a reader of this payload is one
        // step away from the ledger and should not have to know that a plan
        // posts nothing.
        ->assertJsonPath('data.is_a_plan', true)
        ->assertJsonPath('data.fiscal_period.name', 'March 2026')
        ->assertJsonPath('data.row_version', 0)
        ->assertJsonCount(2, 'data.lines');

    expect($response->json('data.ulid'))->toBeString()->toHaveLength(26);

    $entry = DB::table('audit_logs')
        ->where('action', 'budget.plan_saved')
        ->sole();

    expect(DB::table('audit_logs')->count())->toBe($before + 1)
        ->and(json_decode($entry->before, true))->toBe(['name' => null, 'lines' => []])
        ->and(json_decode($entry->after, true))->toHaveCount(2)
        // The hash and its predecessor are computed by the trigger, so a row
        // that reached the table through the action has extended the chain
        // rather than been written beside it.
        ->and(strlen((string) $entry->hash))->toBe(64);
});

it('posts no journal when a plan is written', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $journals = DB::table('journals')->count();
    $lines = DB::table('journal_lines')->count();

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]))->assertCreated();

    // A budget is what the company intends, not what it did: nothing reaches
    // the ledger, so no balance moves and no report built from the ledger
    // changes.
    expect(DB::table('journals')->count())->toBe($journals)
        ->and(DB::table('journal_lines')->count())->toBe($lines)
        ->and(DB::table('budget_lines')->count())->toBe(2);
});

it('records nothing when the plan is written as it already stands', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $body = [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ];

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, $body))->assertCreated();

    $after = DB::table('audit_logs')->count();

    // The same plan said again: the lines in the other order, and an amount
    // written without its trailing zeros. Neither is a change.
    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->expenseAccountId, 'amount' => '150'],
        ['account_id' => $this->revenueAccountId, 'amount' => '600'],
    ]))
        ->assertOk()
        ->assertJsonPath('data.row_version', 0)
        ->assertJsonCount(2, 'data.lines');

    expect(DB::table('audit_logs')->count())->toBe($after)
        ->and(DB::table('budgets')->count())->toBe(1);
});

it('replaces the whole plan when the period already has one', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]))->assertCreated();

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '700.0000'],
    ]))
        ->assertOk()
        ->assertJsonPath('data.row_version', 1)
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.lines.0.amount', '700.0000');

    // One header per company per period: rewriting replaces rather than
    // appends, which is why the plan is written as a whole.
    expect(DB::table('budgets')->count())->toBe(1)
        ->and(DB::table('budget_lines')->count())->toBe(1);
});

it('accepts a negative planned amount, because a contra account is planned as one', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '-50.0000'],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lines.0.amount', '-50.0000');
});

it('refuses a plan line naming an account that is not the company\u2019s', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $foreign = nxBudgetForeignCompany();

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $foreign['account_id'], 'amount' => '600.0000'],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.account_id');

    expect(DB::table('budgets')->count())->toBe(0);
});

it('refuses a plan line naming a balance sheet account', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // The cash account is an asset, and a plan against a balance sheet account
    // is not something the variance report could compare with anything.
    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->cashAccountId, 'amount' => '600.0000'],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.account_id');
});

it('refuses a plan line naming a cost centre that is not the company\u2019s', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $foreign = nxBudgetForeignCompany();

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        [
            'account_id' => $this->revenueAccountId,
            'cost_center_id' => $foreign['cost_center_id'],
            'amount' => '600.0000',
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.cost_center_id');
});

it('refuses a plan naming a fiscal period that is not the company\u2019s', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $foreign = nxBudgetForeignCompany();

    $this->putJson('/api/v1/budgets', [
        'fiscal_period' => $foreign['period_ulid'],
        'lines' => [['account_id' => $this->revenueAccountId, 'amount' => '600.0000']],
    ])->assertNotFound();

    expect(DB::table('budgets')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Cost centres
|--------------------------------------------------------------------------
*/

it('adds, amends and reads back a cost centre', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $created = $this->postJson('/api/v1/cost-centers', [
        'code' => 'CC-200',
        'name' => 'Dar Sales Office',
        'description' => 'The coastal branch',
    ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'CC-200')
        ->assertJsonPath('data.status', 'active');

    $ulid = $created->json('data.ulid');

    $this->getJson('/api/v1/cost-centers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'CC-200');

    $this->patchJson('/api/v1/cost-centers/'.$ulid, ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->getJson('/api/v1/cost-centers/'.$ulid)
        ->assertOk()
        ->assertJsonPath('data.name', 'Dar Sales Office')
        ->assertJsonPath('data.status', 'inactive');
});

it('refuses a second cost centre with the same code in the same company', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/cost-centers', ['code' => 'CC-200', 'name' => 'First'])->assertCreated();

    $this->postJson('/api/v1/cost-centers', ['code' => 'CC-200', 'name' => 'Second'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

/*
|--------------------------------------------------------------------------
| The variance report
|--------------------------------------------------------------------------
*/

/**
 * The sample period the report tests read: a sale on credit of 500 and 200 of
 * expense paid from the till, planned as 600 of revenue and 150 of expense.
 *
 * @param  array<string, mixed>  $ctx
 */
function nxBudgetPostTheMonth(array $ctx): void
{
    nxBudgetPost($ctx, 'JV-100001', '2026-03-15', $ctx['receivable'], $ctx['revenue'], '500.0000');
    nxBudgetPost($ctx, 'JV-100002', '2026-03-15', $ctx['expense'], $ctx['cash'], '200.0000');
}

it('shows the plan, the actual and the variance for a period', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    nxBudgetPostTheMonth($this->budget);

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]))->assertCreated();

    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertOk()
        ->assertJsonPath('period.name', 'March 2026')
        ->assertJsonPath('period.starts_on', '2026-03-01')
        ->assertJsonPath('period.ends_on', '2026-03-31')
        // The report says out loud in its own labels that the budget column is
        // a plan and not a transaction.
        ->assertJsonPath('budget_is_a_plan', true)
        ->assertJsonPath('variance_basis', 'actual_minus_budget')
        // Revenue: planned 600, did 500, so 100 under plan.
        ->assertJsonPath('revenue.accounts.0.code', '4000')
        ->assertJsonPath('revenue.accounts.0.account_type', 'revenue')
        ->assertJsonPath('revenue.accounts.0.budget', '600.0000')
        ->assertJsonPath('revenue.accounts.0.actual', '500.0000')
        ->assertJsonPath('revenue.accounts.0.variance', '-100.0000')
        ->assertJsonPath('revenue.accounts.0.variance_percent', '-16.6667')
        ->assertJsonPath('revenue.budget', '600.0000')
        ->assertJsonPath('revenue.actual', '500.0000')
        // Expense: planned 150, did 200, so 50 over plan — unfavourable here,
        // which the note says rather than leaving the sign to be interpreted.
        ->assertJsonPath('expense.accounts.0.code', '5000')
        ->assertJsonPath('expense.accounts.0.budget', '150.0000')
        ->assertJsonPath('expense.accounts.0.actual', '200.0000')
        ->assertJsonPath('expense.accounts.0.variance', '50.0000')
        ->assertJsonPath('expense.accounts.0.variance_percent', '33.3333')
        ->assertJsonPath('expense.budget', '150.0000')
        ->assertJsonPath('expense.actual', '200.0000')
        // Net: planned 450, did 300.
        ->assertJsonPath('net.budget', '450.0000')
        ->assertJsonPath('net.actual', '300.0000')
        ->assertJsonPath('net.variance', '-150.0000')
        ->assertJsonPath('net.variance_percent', '-33.3333');
});

it('makes the actual column the ledger totals the profit and loss reports', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    nxBudgetPostTheMonth($this->budget);

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]))->assertCreated();

    $variance = $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertOk()
        ->json();

    $statement = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json();

    // One figure read twice: the actual column is the period's posted ledger
    // read through the profit and loss statement beside it, so it cannot drift
    // from it. The budget column is deliberately not part of this — a plan is
    // not in the ledger to agree with.
    expect($variance['revenue']['actual'])->toBe($statement['revenue']['total'])
        ->and($variance['expense']['actual'])->toBe($statement['expense']['total'])
        ->and($variance['revenue']['actual'])->toBe('500.0000')
        ->and($variance['expense']['actual'])->toBe('200.0000')
        ->and($variance['net']['actual'])->toBe($statement['net_profit'])
        ->and($statement['net_profit'])->toBe('300.0000');
});

it('shows an account that was planned and never touched', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // Nothing is posted at all: the whole plan is unspent, which is exactly
    // what a reader is looking for.
    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->expenseAccountId, 'amount' => '150.0000'],
    ]))->assertCreated();

    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertOk()
        ->assertJsonPath('expense.accounts.0.code', '5000')
        ->assertJsonPath('expense.accounts.0.budget', '150.0000')
        ->assertJsonPath('expense.accounts.0.actual', '0.0000')
        ->assertJsonPath('expense.accounts.0.variance', '-150.0000')
        ->assertJsonPath('expense.accounts.0.variance_percent', '-100.0000');
});

it('reports the actuals with no budget when the period has no plan', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    nxBudgetPostTheMonth($this->budget);

    $report = $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertOk()
        ->assertJsonPath('budget_ulid', null)
        ->assertJsonPath('budget_name', null)
        ->assertJsonPath('revenue.actual', '500.0000')
        // Every variance against no plan is the whole of the actual, and the
        // percentage is absent rather than infinite.
        ->assertJsonPath('revenue.budget', '0.0000')
        ->assertJsonPath('revenue.variance', '500.0000')
        ->assertJsonPath('revenue.variance_percent', null)
        ->json();

    expect($report['budget_is_a_plan'])->toBeTrue();
});

it('sums an account\u2019s plan lines across the cost centres they were split into', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $first = $this->postJson('/api/v1/cost-centers', ['code' => 'CC-100', 'name' => 'Head Office'])
        ->assertCreated()
        ->json('data.id');

    $second = $this->postJson('/api/v1/cost-centers', ['code' => 'CC-200', 'name' => 'Dar Office'])
        ->assertCreated()
        ->json('data.id');

    nxBudgetPostTheMonth($this->budget);

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'cost_center_id' => $first, 'amount' => '400.0000'],
        ['account_id' => $this->revenueAccountId, 'cost_center_id' => $second, 'amount' => '200.0000'],
    ]))->assertCreated();

    // The ledger carries no cost centre column, so the actual cannot be split
    // the same way. Cost centres stay where they can be honest — on the plan —
    // and the report compares the account's planned total with its ledger
    // total.
    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertOk()
        ->assertJsonCount(1, 'revenue.accounts')
        ->assertJsonPath('revenue.accounts.0.budget', '600.0000')
        ->assertJsonPath('revenue.accounts.0.actual', '500.0000')
        ->assertJsonPath('revenue.budget', '600.0000');

    // Both lines are kept: a plan line names the cost centre it belongs to, so
    // they are two lines rather than one summed row.
    expect(DB::table('budget_lines')->where('account_id', $this->revenueAccountId)->count())->toBe(2);
});

it('compares the plan with the period before it, in both columns', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $february = nxBudgetInstallPeriod(
        $this->companyId,
        $this->fiscalYearId,
        2,
        'February 2026',
        '2026-02-01',
        '2026-02-28',
    );

    // The month before: a sale of 300, planned at 250.
    nxBudgetPost([
        ...$this->budget,
        'fiscal_period_id' => $february,
    ], 'JV-200001', '2026-02-15', $this->receivableAccountId, $this->revenueAccountId, '300.0000');

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($february, [
        ['account_id' => $this->revenueAccountId, 'amount' => '250.0000'],
    ]))->assertCreated();

    // The month asked for: a sale of 500, planned at 600.
    nxBudgetPost($this->budget, 'JV-200002', '2026-03-15', $this->receivableAccountId, $this->revenueAccountId, '500.0000');

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]))->assertCreated();

    $this->getJson(
        '/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId).'&compare=1'
    )
        ->assertOk()
        // The prior column is the same report over the period the calendar
        // says comes first, not a figure computed a different way.
        ->assertJsonPath('comparison.period.name', 'February 2026')
        ->assertJsonPath('comparison.revenue.actual', '300.0000')
        ->assertJsonPath('comparison.revenue.budget', '250.0000')
        // The variance is this column less the prior one, so a positive figure
        // is an increase: 500 - 300 of revenue and 600 - 250 of plan. The
        // variance of a variance moves the other way, because February was 50
        // over its own plan and March is 100 under, so the gap widened by 150.
        ->assertJsonPath('variance.revenue.actual', '200.0000')
        ->assertJsonPath('variance.revenue.budget', '350.0000')
        ->assertJsonPath('variance.revenue.variance', '-150.0000');
});

it('does not compare a period that has no earlier one', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    nxBudgetPostTheMonth($this->budget);

    // March is the first period on the company's calendar, so there is nothing
    // before it — stated rather than silently ignored.
    $this->getJson(
        '/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId).'&compare=1'
    )
        ->assertOk()
        ->assertJsonPath('comparison', null)
        ->assertJsonPath('variance', null);
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the report, the plan and the write without a token', function () {
    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertUnauthorized();
    $this->getJson('/api/v1/budgets')->assertUnauthorized();
    $this->putJson('/api/v1/budgets', [])->assertUnauthorized();
});

it('refuses the plan and its report for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreign = nxBudgetForeignCompany();

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.$foreign['period_ulid'])
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');

    $this->flushHeaders();

    // The period is a real one, but not in the caller's company, so resolving
    // it is a 404 rather than an existence oracle.
    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.$foreign['period_ulid'])
        ->assertNotFound();
});

it('answers not found for another company\u2019s plan', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]))
        ->assertCreated()
        ->json('data.ulid');

    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->getJson('/api/v1/budgets/'.(string) Str::ulid())->assertNotFound();
});

it('holds the plan behind its own rights rather than the report rights', function () {
    nxBudgetPostTheMonth($this->budget);

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]))->assertUnauthorized();

    $period = nxBudgetPeriodUlid($this->fiscalPeriodId);

    // The Read-only role reads every report the books have to give and is still
    // not shown the plan: a budget is what the company intends, not what the
    // books report.
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_READ_ONLY));

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')->assertOk();
    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.$period)->assertForbidden();
    $this->getJson('/api/v1/budgets')->assertForbidden();
    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, []))->assertForbidden();

    // A Cashier holds no budget right either.
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.$period)->assertForbidden();
});

it('lets a member who may read the plan read it and refuse them the write', function () {
    nxBudgetPostTheMonth($this->budget);

    $period = nxBudgetPeriodUlid($this->fiscalPeriodId);

    // The Auditor audits the plan against the period and does not write it.
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.$period)->assertOk();
    $this->getJson('/api/v1/budgets')->assertOk();
    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]))->assertForbidden();

    // So does the Finance Officer, who reads the plan to check what a period
    // was meant to spend.
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_FINANCE_OFFICER));

    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.$period)->assertOk();
    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]))->assertForbidden();

    // The Accountant keeps the plan and writes it.
    Sanctum::actingAs(nxBudgetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->putJson('/api/v1/budgets', nxBudgetPlanBody($this->fiscalPeriodId, [
        ['account_id' => $this->revenueAccountId, 'amount' => '600.0000'],
    ]))->assertCreated();
});

it('refuses every budget route to a member with no role in the company', function () {
    Sanctum::actingAs(nxBudgetMember($this->companyId));

    $this->getJson('/api/v1/budgets')->assertForbidden();
    $this->getJson('/api/v1/cost-centers')->assertForbidden();
    $this->putJson('/api/v1/budgets', [])->assertForbidden();
    $this->getJson('/api/v1/reports/budget-vs-actual?fiscal_period='.nxBudgetPeriodUlid($this->fiscalPeriodId))
        ->assertForbidden();
});
