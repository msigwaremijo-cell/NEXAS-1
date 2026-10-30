<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The statement is built from the posted ledger inside MySQL, so it is
// exercised over a real schema: which lines count as posted is decided by the
// triggers, not by PHP. The fixtures for it are raw inserts for the same
// reason — a line may only be written while its journal is a draft, and only
// the UPDATE to `posted` reaches the ledger.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // The fixture accounts are raw rows carrying no classification, so every
    // account this file posts a counter line to says what it is. Buying
    // equipment and drawing down a loan need two accounts the fixtures do not
    // install: the investing and financing sides.
    $this->cashFlow = [
        'company_id' => $this->companyId,
        'fiscal_year_id' => $this->fiscalYearId,
        'fiscal_period_id' => $this->fiscalPeriodId,
        'currency' => 'TZS',
        'cash' => $this->cashAccountId,
        'bank' => $this->bankLedgerAccountId,
        'receivable' => $this->receivableAccountId,
        'expense' => $this->expenseAccountId,
        // Deliberately left unclassified, so the unclassified line has a
        // counter account to point at.
        'revenue' => $this->revenueAccountId,
        'equipment' => nxCashAccountId($this->companyId, '1550'),
        'loan' => nxCashAccountId($this->companyId, '2510'),
    ];

    nxCashCategorize($this->cashFlow['cash'], CashFlowCategory::InternalTransfer->value);
    nxCashCategorize($this->cashFlow['bank'], CashFlowCategory::InternalTransfer->value);
    nxCashCategorize($this->cashFlow['receivable'], CashFlowCategory::Operating->value);
    nxCashCategorize($this->cashFlow['expense'], CashFlowCategory::Operating->value);
    nxCashCategorize($this->cashFlow['equipment'], CashFlowCategory::Investing->value);
    nxCashCategorize($this->cashFlow['loan'], CashFlowCategory::Financing->value);
});

/**
 * The id of a fixture account, by code.
 */
function nxCashAccountId(int $companyId, string $code): int
{
    $id = DB::table('accounts')->where('company_id', $companyId)->where('code', $code)->value('id');

    if ($id === null) {
        $id = DB::table('accounts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => 'Fixture Account '.$code,
            'account_type' => $code === '2510' ? 'liability' : 'asset',
            'normal_balance' => $code === '2510' ? 'credit' : 'debit',
            'depth' => 0,
            'is_postable' => true,
            'is_cash' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return (int) $id;
}

/**
 * The whole accounts row, for a test that needs the ULID it is addressed by.
 */
function nxCashAccount(int $companyId, string $code): object
{
    return DB::table('accounts')->where('company_id', $companyId)->where('code', $code)->firstOrFail();
}

/**
 * Set an account's cash flow category directly, which is what the seeder does.
 * The guarded write path is exercised separately below.
 */
function nxCashCategorize(int $accountId, ?string $category): void
{
    DB::table('accounts')->where('id', $accountId)->update(['cash_flow_category' => $category]);
}

/**
 * Write a two-line journal dated on a given day, and post it.
 *
 * The date is written to the journal and to both lines, because a trigger
 * refuses a line that disagrees with its journal's posting date.
 *
 * @param  array<string, mixed>  $ctx
 */
function nxCashPost(
    array $ctx,
    string $number,
    string $date,
    int $debitAccountId,
    int $creditAccountId,
    string $amount,
    bool $post = true,
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

    // Draft until this runs: the ledger, and therefore both reports, can only
    // see the movement once the journal is posted.
    if ($post) {
        DB::table('journals')->where('id', $journalId)->update([
            'status' => 'posted',
            'posted_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * A member of a company, holding exactly one company-scoped role. A null role
 * leaves them a member with no rights at all.
 */
function nxCashMember(int $companyId, ?string $role = null): User
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
 * A second tenant, used to prove a report cannot be pointed at books the
 * caller is not a member of.
 */
function nxCashForeignCompanyId(): int
{
    $now = now();

    return DB::table('companies')->insertGetId([
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
}

/**
 * Four movements that each touch cash with a counter line in a different
 * activity: a receipt, equipment bought for cash, a loan drawn down and an
 * expense paid. Cash moves by 100 - 300 + 500 - 50.
 *
 * @param  array<string, mixed>  $ctx
 */
function nxCashPostTheQuarter(array $ctx): void
{
    nxCashPost($ctx, 'JV-100001', '2026-03-15', $ctx['cash'], $ctx['receivable'], '100.0000');
    nxCashPost($ctx, 'JV-100002', '2026-03-15', $ctx['equipment'], $ctx['cash'], '300.0000');
    nxCashPost($ctx, 'JV-100003', '2026-03-15', $ctx['cash'], $ctx['loan'], '500.0000');
    nxCashPost($ctx, 'JV-100004', '2026-03-15', $ctx['expense'], $ctx['cash'], '50.0000');
}

/*
|--------------------------------------------------------------------------
| The rules of the statement
|--------------------------------------------------------------------------
*/

it('splits the period into the three activities by the counter account', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxCashPostTheQuarter($this->cashFlow);

    $response = $this->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
        ->assertOk();

    // Operating: the receivables credit is the inflow and the expense debit the
    // outflow. A counter credit is money arriving, a counter debit money
    // leaving.
    $response
        ->assertJsonPath('activities.0.category', 'operating')
        ->assertJsonPath('activities.0.inflow', '100.0000')
        ->assertJsonPath('activities.0.outflow', '50.0000')
        ->assertJsonPath('activities.0.net', '50.0000')
        ->assertJsonPath('activities.1.category', 'investing')
        ->assertJsonPath('activities.1.inflow', '0.0000')
        ->assertJsonPath('activities.1.outflow', '300.0000')
        ->assertJsonPath('activities.1.net', '-300.0000')
        ->assertJsonPath('activities.2.category', 'financing')
        ->assertJsonPath('activities.2.inflow', '500.0000')
        ->assertJsonPath('activities.2.outflow', '0.0000')
        ->assertJsonPath('activities.2.net', '500.0000');

    // Cash moved by 100 - 300 + 500 - 50.
    $response
        ->assertJsonPath('net_activities', '250.0000')
        ->assertJsonPath('net_movement', '250.0000')
        ->assertJsonPath('opening_cash', '0.0000')
        ->assertJsonPath('closing_cash', '250.0000')
        ->assertJsonPath('balanced', true)
        ->assertJsonPath('complete', true)
        ->assertJsonPath('internal_transfers.net', '0.0000')
        ->assertJsonPath('unclassified.net', '0.0000');

    // The pool is declared rather than listed: it is the accounts the chart
    // flags as cash or bank.
    expect($response->json('cash_accounts'))->toHaveCount(2);
});

it('leaves a transfer between the company\'s own accounts out of the activities', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // Some operating money to move, so the transfer has something to carry.
    nxCashPost($this->cashFlow, 'JV-200001', '2026-03-15', $this->cashFlow['cash'], $this->cashFlow['receivable'], '300.0000');

    // And 200 of it paid from the till into the bank. Both sides are money, so
    // there is no counter line and therefore no activity.
    nxCashPost($this->cashFlow, 'JV-200002', '2026-03-15', $this->cashFlow['bank'], $this->cashFlow['cash'], '200.0000');

    $this->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('activities.0.net', '300.0000')
        ->assertJsonPath('activities.1.net', '0.0000')
        ->assertJsonPath('activities.2.net', '0.0000')
        ->assertJsonPath('internal_transfers.inflow', '0.0000')
        ->assertJsonPath('internal_transfers.outflow', '0.0000')
        ->assertJsonPath('net_activities', '300.0000')
        ->assertJsonPath('net_movement', '300.0000')
        // The money did not leave the company, so the total is unmoved by the
        // transfer: opening plus the operating receipt, and nothing else.
        ->assertJsonPath('opening_cash', '0.0000')
        ->assertJsonPath('closing_cash', '300.0000')
        ->assertJsonPath('balanced', true);

    // And the position still shows it, split across both accounts.
    $this->getJson('/api/v1/reports/cash-position?as_of=2026-03-31')
        ->assertOk()
        ->assertJsonPath('total', '300.0000');
});

it('shows an unclassified movement on its own line and marks the report incomplete', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The revenue fixture carries no classification: nothing in this test
    // classifies it.
    nxCashPost($this->cashFlow, 'JV-300001', '2026-03-15', $this->cashFlow['cash'], $this->cashFlow['revenue'], '75.0000');

    $this->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('activities.0.net', '0.0000')
        ->assertJsonPath('unclassified.category', 'unclassified')
        ->assertJsonPath('unclassified.inflow', '75.0000')
        ->assertJsonPath('unclassified.net', '75.0000')
        // Shown, not dropped — and the report says out loud that it could not
        // explain everything.
        ->assertJsonPath('complete', false)
        // The arithmetic still closes, because the line is part of the movement
        // rather than a hole in it.
        ->assertJsonPath('net_movement', '75.0000')
        ->assertJsonPath('closing_cash', '75.0000')
        ->assertJsonPath('balanced', true);
});

it('counts only the posted journals and ignores a draft', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxCashPost($this->cashFlow, 'JV-400001', '2026-03-15', $this->cashFlow['cash'], $this->cashFlow['receivable'], '100.0000');

    // A draft's lines are already physically in journal_lines. They have not
    // reached the ledger, so nothing below may count them.
    nxCashPost($this->cashFlow, 'JV-400002', '2026-03-15', $this->cashFlow['cash'], $this->cashFlow['receivable'], '900.0000', post: false);

    $this->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('activities.0.inflow', '100.0000')
        ->assertJsonPath('closing_cash', '100.0000');
});

it('opens the period on the standing the day before it starts', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // Earlier in the month: outside the window's movement, but the standing the
    // window opens on.
    nxCashPost($this->cashFlow, 'JV-500001', '2026-03-10', $this->cashFlow['cash'], $this->cashFlow['receivable'], '40.0000');
    nxCashPost($this->cashFlow, 'JV-500002', '2026-03-20', $this->cashFlow['cash'], $this->cashFlow['receivable'], '60.0000');

    $this->getJson('/api/v1/reports/cash-flow?from=2026-03-15&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('opening_cash', '40.0000')
        ->assertJsonPath('net_movement', '60.0000')
        ->assertJsonPath('closing_cash', '100.0000')
        ->assertJsonPath('balanced', true);
});

it('reports the whole ledger from a zero opening when no dates are given', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxCashPostTheQuarter($this->cashFlow);

    $this->getJson('/api/v1/reports/cash-flow')
        ->assertOk()
        ->assertJsonPath('opening_cash', '0.0000')
        ->assertJsonPath('net_movement', '250.0000')
        ->assertJsonPath('closing_cash', '250.0000')
        ->assertJsonPath('balanced', true);
});

it('states that it reads the accounts\u2019 current categories', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/cash-flow')
        ->assertOk()
        ->assertJsonPath('uses_current_categories', true)
        ->assertJsonPath(
            'note',
            'Activities are taken from the cash flow categories the accounts hold now, '
            .'so reclassifying an account changes this report for every period it covers.'
        );
});

/*
|--------------------------------------------------------------------------
| The comparative column
|--------------------------------------------------------------------------
*/

it('compares the statement with the window of equal length immediately before it', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The window before the one asked for: 2026-03-08 to 2026-03-19, the same
    // twelve days as 2026-03-20 to 2026-03-31. A receipt of 40 less an expense
    // of 10 leaves 30 of operating money.
    nxCashPost($this->cashFlow, 'JV-800001', '2026-03-10', $this->cashFlow['cash'], $this->cashFlow['receivable'], '40.0000');
    nxCashPost($this->cashFlow, 'JV-800002', '2026-03-12', $this->cashFlow['expense'], $this->cashFlow['cash'], '10.0000');

    // The window asked for: a receipt of 100 and equipment bought for cash.
    nxCashPost($this->cashFlow, 'JV-800003', '2026-03-22', $this->cashFlow['cash'], $this->cashFlow['receivable'], '100.0000');
    nxCashPost($this->cashFlow, 'JV-800004', '2026-03-25', $this->cashFlow['equipment'], $this->cashFlow['cash'], '30.0000');

    $response = $this->getJson('/api/v1/reports/cash-flow?from=2026-03-20&to=2026-03-31&compare=1')
        ->assertOk();

    // The current column: 100 in less 30 out.
    $response
        ->assertJsonPath('net_activities', '70.0000')
        ->assertJsonPath('net_movement', '70.0000')
        ->assertJsonPath('closing_cash', '100.0000');

    // The prior column is the same statement over the window immediately before
    // this one, not a figure computed a different way.
    $response
        ->assertJsonPath('comparison.from', '2026-03-08')
        ->assertJsonPath('comparison.to', '2026-03-19')
        ->assertJsonPath('comparison.net_movement', '30.0000')
        ->assertJsonPath('comparison.closing_cash', '30.0000');

    // The variance is the current column less the prior one, activity by
    // activity and in total: a positive figure is an increase.
    $response
        ->assertJsonPath('variance.operating', '70.0000')
        ->assertJsonPath('variance.investing', '-30.0000')
        ->assertJsonPath('variance.financing', '0.0000')
        ->assertJsonPath('variance.net_movement', '40.0000');
});

it('makes the comparative closing cash the window\'s opening cash and its position', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // One receipt in the window before, one in the window asked for.
    nxCashPost($this->cashFlow, 'JV-810001', '2026-03-10', $this->cashFlow['cash'], $this->cashFlow['receivable'], '40.0000');
    nxCashPost($this->cashFlow, 'JV-810002', '2026-03-22', $this->cashFlow['cash'], $this->cashFlow['receivable'], '100.0000');

    $statement = $this->getJson('/api/v1/reports/cash-flow?from=2026-03-20&to=2026-03-31&compare=1')
        ->assertOk()
        ->json();

    $position = $this->getJson('/api/v1/reports/cash-position?as_of=2026-03-19')
        ->assertOk()
        ->json('total');

    // The prior window ends the day before this one starts, so its closing
    // balance is exactly what this window opens on.
    expect($statement['comparison']['closing_cash'])->toBe('40.0000')
        ->and($statement['opening_cash'])->toBe($statement['comparison']['closing_cash'])
        // And it is the same money the position report reads at that date.
        ->and($position)->toBe($statement['comparison']['closing_cash'])
        ->and($statement['balanced'])->toBeTrue()
        ->and($statement['comparison']['balanced'])->toBeTrue();
});

it('does not compare a statement that covers the whole ledger', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxCashPostTheQuarter($this->cashFlow);

    // There is no window before all of the ledger, so a report with no dates is
    // single-column however compare is set — stated rather than silently
    // ignored.
    $this->getJson('/api/v1/reports/cash-flow?compare=1')
        ->assertOk()
        ->assertJsonPath('comparison', null)
        ->assertJsonPath('variance', null);
});

/*
|--------------------------------------------------------------------------
| The two reports agree
|--------------------------------------------------------------------------
*/

it('makes closing cash from the statement equal the cash position total', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxCashPostTheQuarter($this->cashFlow);

    // A transfer, so the agreement is not only true of a single-account pool.
    nxCashPost($this->cashFlow, 'JV-600001', '2026-03-15', $this->cashFlow['bank'], $this->cashFlow['cash'], '120.0000');

    $closing = $this->getJson('/api/v1/reports/cash-flow?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json('closing_cash');

    $position = $this->getJson('/api/v1/reports/cash-position?as_of=2026-03-31')
        ->assertOk()
        ->json('total');

    // One figure read twice: the statement's closing balance and the position's
    // total are the same money, and they come from different queries.
    expect($closing)->toBe('250.0000')
        ->and($position)->toBe($closing);
});

it('reports each money account in the position, including one that never moved', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxCashPost($this->cashFlow, 'JV-700001', '2026-03-15', $this->cashFlow['cash'], $this->cashFlow['receivable'], '90.0000');

    $this->getJson('/api/v1/reports/cash-position?as_of=2026-03-31')
        ->assertOk()
        ->assertJsonPath('as_of', '2026-03-31')
        ->assertJsonCount(2, 'accounts')
        ->assertJsonPath('accounts.0.code', '1000')
        ->assertJsonPath('accounts.0.balance', '90.0000')
        // Absent from the ledger's answer means zero, not missing.
        ->assertJsonPath('accounts.1.code', '1010')
        ->assertJsonPath('accounts.1.balance', '0.0000')
        ->assertJsonPath('total', '90.0000');
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses both reports without a token', function () {
    $this->getJson('/api/v1/reports/cash-flow')->assertUnauthorized();
    $this->getJson('/api/v1/reports/cash-position')->assertUnauthorized();
});

it('refuses both reports for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxCashForeignCompanyId())
        ->getJson('/api/v1/reports/cash-flow')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');

    $this->withHeader('X-Company-Id', (string) nxCashForeignCompanyId())
        ->getJson('/api/v1/reports/cash-position')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

it('refuses both reports to a member with no role in the company', function () {
    // Every seeded role holds report.view, so the member who lacks it is one
    // who has been given no role at all.
    Sanctum::actingAs(nxCashMember($this->companyId));

    $this->getJson('/api/v1/reports/cash-flow')->assertForbidden();
    $this->getJson('/api/v1/reports/cash-position')->assertForbidden();
});

it('lets a role holding report.view read both reports', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->getJson('/api/v1/reports/cash-flow')->assertOk();
    $this->getJson('/api/v1/reports/cash-position')->assertOk();
});

it('refuses a range that runs backwards', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/cash-flow?from=2026-03-31&to=2026-03-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

/*
|--------------------------------------------------------------------------
| Classifying an account
|--------------------------------------------------------------------------
*/

it('classifies an account and records the change in the audit chain', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $account = nxCashAccount($this->companyId, '1550');
    $before = DB::table('audit_logs')->count();

    $this->patchJson('/api/v1/accounts/'.$account->ulid.'/cash-flow-category', [
        'cash_flow_category' => CashFlowCategory::Operating->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.cash_flow_category', 'operating');

    expect(DB::table('accounts')->where('id', $account->id)->value('cash_flow_category'))
        ->toBe('operating');

    $entry = DB::table('audit_logs')
        ->where('action', 'account.cash_flow_category_changed')
        ->where('auditable_id', $account->id)
        ->sole();

    expect(DB::table('audit_logs')->count())->toBe($before + 1)
        ->and(json_decode($entry->before, true))->toBe(['cash_flow_category' => 'investing'])
        ->and(json_decode($entry->after, true))->toBe(['cash_flow_category' => 'operating'])
        // The hash and its predecessor are computed by the trigger, so a row
        // that reached the table through the action has extended the chain
        // rather than been written beside it.
        ->and(strlen((string) $entry->hash))->toBe(64);
});

it('records nothing when the classification is set to what it already is', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $account = nxCashAccount($this->companyId, '1550');
    $before = DB::table('audit_logs')->count();

    $this->patchJson('/api/v1/accounts/'.$account->ulid.'/cash-flow-category', [
        'cash_flow_category' => CashFlowCategory::Investing->value,
    ])->assertOk();

    // An audit log full of entries that record no change buries the entries
    // that matter, so a no-op writes no row.
    expect(DB::table('audit_logs')->count())->toBe($before);
});

it('clears a classification with an explicit null', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $account = nxCashAccount($this->companyId, '1550');

    $this->patchJson('/api/v1/accounts/'.$account->ulid.'/cash-flow-category', [
        'cash_flow_category' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.cash_flow_category', null);

    expect(DB::table('accounts')->where('id', $account->id)->value('cash_flow_category'))
        ->toBeNull();
});

it('refuses a category outside the set rather than ignoring it', function () {
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $account = nxCashAccount($this->companyId, '1550');

    $this->patchJson('/api/v1/accounts/'.$account->ulid.'/cash-flow-category', [
        'cash_flow_category' => 'banana',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cash_flow_category');
});

it('refuses the classification to a role without account.manage', function () {
    // A cashier reads the books and takes money in, but the chart of accounts
    // is not theirs to reclassify.
    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $account = nxCashAccount($this->companyId, '1550');

    $this->patchJson('/api/v1/accounts/'.$account->ulid.'/cash-flow-category', [
        'cash_flow_category' => CashFlowCategory::Operating->value,
    ])->assertForbidden();

    expect(DB::table('accounts')->where('id', $account->id)->value('cash_flow_category'))
        ->toBe('investing');
});

it('answers not found for an account that belongs to another company', function () {
    $foreignCompanyId = nxCashForeignCompanyId();
    $now = now();

    DB::table('accounts')->insert([
        'ulid' => (string) Str::ulid(),
        'company_id' => $foreignCompanyId,
        'code' => '1550',
        'name' => 'Office Equipment',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $foreign = DB::table('accounts')->where('company_id', $foreignCompanyId)->sole();

    Sanctum::actingAs(nxCashMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The id is real, but not in the caller's company, so the answer is 404
    // rather than a 403 that would confirm the account exists elsewhere.
    $this->patchJson('/api/v1/accounts/'.$foreign->ulid.'/cash-flow-category', [
        'cash_flow_category' => CashFlowCategory::Operating->value,
    ])->assertNotFound();
});
