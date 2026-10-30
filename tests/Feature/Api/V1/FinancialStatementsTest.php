<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The statements read the posted ledger straight off MySQL, and the year-end
// close writes a real journal through CreateJournal/PostJournal, whose balance
// trigger and period lock only exist in the database. So both halves are
// exercised over a real schema.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxFiscalMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, used to prove a statement cannot be pointed at books the
 * caller is not a member of.
 */
function nxFiscalForeignCompanyId(): int
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
 * The last period of the fiscal year, so the year-end close has somewhere to
 * date its closing journal. makeFixtures() installs March only.
 */
function nxFiscalFinalPeriod(int $companyId, int $fiscalYearId): int
{
    return DB::table('fiscal_periods')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'period_no' => 12,
        'name' => 'December 2026',
        'starts_on' => '2026-12-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the statements without a token', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
})->with([
    '/api/v1/reports/profit-and-loss',
    '/api/v1/reports/balance-sheet',
]);

it('lets an auditor read both statements', function () {
    // The auditor holds report.view and nothing else, and a statement is what
    // an audit is read against, so both have to answer.
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/profit-and-loss')->assertOk();

    $this->getJson('/api/v1/reports/balance-sheet')->assertOk();
});

it('refuses a statement for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxFiscalForeignCompanyId())
        ->getJson('/api/v1/reports/profit-and-loss')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

/*
|--------------------------------------------------------------------------
| Profit and loss
|--------------------------------------------------------------------------
*/

it('reports revenue less expense over the period', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    // Cash in 500, cash out 200: the year's result is a profit of 300.
    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    $expense = $this->makeJournal('JV-000002');
    $this->addLine($expense, 1, $expenseAccountId, '200.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($expense);

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')
        ->assertOk()
        ->assertJsonCount(1, 'revenue.accounts')
        ->assertJsonPath('revenue.accounts.0.code', '4000')
        // Revenue is credit-normal, so its own side is the positive one.
        ->assertJsonPath('revenue.accounts.0.amount', '500.0000')
        ->assertJsonPath('revenue.total', '500.0000')
        ->assertJsonCount(1, 'expense.accounts')
        ->assertJsonPath('expense.accounts.0.code', '6000')
        ->assertJsonPath('expense.accounts.0.amount', '200.0000')
        ->assertJsonPath('expense.total', '200.0000')
        ->assertJsonPath('net_profit', '300.0000');
});

it('reports a loss as a negative net profit rather than a negative expense', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    $expense = $this->makeJournal('JV-000001');
    $this->addLine($expense, 1, $expenseAccountId, '800.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '800.0000');
    $this->postJournal($expense);

    $this->getJson('/api/v1/reports/profit-and-loss')
        ->assertOk()
        ->assertJsonCount(0, 'revenue.accounts')
        ->assertJsonPath('revenue.total', '0.0000')
        ->assertJsonPath('expense.total', '800.0000')
        ->assertJsonPath('net_profit', '-800.0000');
});

it('leaves a temporary account out when nothing moved inside the window', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    $expense = $this->makeJournal('JV-000002');
    $this->addLine($expense, 1, $expenseAccountId, '200.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($expense);

    // The window opens after the only postings, so the statement is empty
    // rather than being a window with two zero rows in it.
    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertJsonCount(0, 'revenue.accounts')
        ->assertJsonCount(0, 'expense.accounts')
        ->assertJsonPath('net_profit', '0.0000');
});

it('refuses a range that runs backwards', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-12-31&to=2026-01-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

it('keeps a closing journal out of the year it closed', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $retainedEarningsId = $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');

    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    // A closing entry is dated the closed year's own last day, so it falls
    // inside the window this report covers. Counting it would take a finished
    // year's revenue back to nothing — the statement would report a nil year
    // for the year that earned the most.
    $closing = $this->makeJournal('JV-000002');
    DB::table('journals')->where('id', $closing)->update([
        'journal_type' => 'closing',
        'posting_date' => '2026-12-31',
    ]);
    $this->addLine($closing, 1, $this->revenueAccountId, '500.0000', '0', '2026-12-31');
    $this->addLine($closing, 2, $retainedEarningsId, '0', '500.0000', '2026-12-31');
    $this->postJournal($closing);

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')
        ->assertOk()
        ->assertJsonPath('revenue.total', '500.0000')
        ->assertJsonPath('net_profit', '500.0000');
});

/*
|--------------------------------------------------------------------------
| Balance sheet
|--------------------------------------------------------------------------
*/

it('balances assets against liabilities, equity and the unclosed result', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    $expense = $this->makeJournal('JV-000002');
    $this->addLine($expense, 1, $expenseAccountId, '200.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($expense);

    // No year end has happened, so revenue and expense still stand in the
    // ledger and no closing entry has moved them anywhere. The statement only
    // balances because it reports them as the unclosed result: 300 of profit
    // that the chart has not yet carried into retained earnings.
    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonCount(1, 'assets.accounts')
        ->assertJsonPath('assets.accounts.0.code', '1000')
        ->assertJsonPath('assets.accounts.0.amount', '300.0000')
        ->assertJsonPath('assets.total', '300.0000')
        ->assertJsonCount(0, 'liabilities.accounts')
        ->assertJsonPath('liabilities.total', '0.0000')
        ->assertJsonCount(0, 'equity.accounts')
        ->assertJsonPath('equity.unclosed_result', '300.0000')
        ->assertJsonPath('equity.total', '300.0000')
        ->assertJsonPath('total_assets', '300.0000')
        ->assertJsonPath('total_liabilities_and_equity', '300.0000')
        ->assertJsonPath('balanced', true);
});

it('reads an equity account on its own side and keeps the statement balancing', function () {
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $capitalId = $this->makeAccount('3100', 'Share Capital', 'equity', 'credit');

    // The owner puts 1000 in: cash debit, capital credit.
    $journal = $this->makeJournal('JV-000001');
    $this->addLine($journal, 1, $this->cashAccountId, '1000.0000', '0');
    $this->addLine($journal, 2, $capitalId, '0', '1000.0000');
    $this->postJournal($journal);

    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('assets.total', '1000.0000')
        ->assertJsonCount(1, 'equity.accounts')
        ->assertJsonPath('equity.accounts.0.code', '3100')
        // Credit-normal, so the credit reads as a positive figure.
        ->assertJsonPath('equity.accounts.0.amount', '1000.0000')
        ->assertJsonPath('equity.unclosed_result', '0.0000')
        ->assertJsonPath('equity.total', '1000.0000')
        ->assertJsonPath('balanced', true);
});

/*
|--------------------------------------------------------------------------
| Fiscal year close
|--------------------------------------------------------------------------
*/

it('closes the year, writes the closing journal and shuts every period', function () {
    nxFiscalFinalPeriod($this->companyId, $this->fiscalYearId);

    $retainedEarningsId = $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    // Deliberately numbered well clear of JV-000001: the closing journal comes
    // out of the same gapless sequence, which starts at one.
    $this->postJournal($this->balancedJournal('JV-000100', '500.0000'));

    $expense = $this->makeJournal('JV-000101');
    $this->addLine($expense, 1, $expenseAccountId, '200.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($expense);

    $yearUlid = DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('ulid');

    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.periods_closed', 2)
        ->assertJsonPath('data.closing_journal.journal_type', 'closing')
        ->assertJsonPath('data.closing_journal.status', 'posted')
        ->assertJsonPath('data.closing_journal.total_debit', '500.0000')
        ->assertJsonPath('data.closing_journal.total_credit', '500.0000');

    // The year and both its periods stopped accepting postings.
    expect(DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('status'))->toBe('closed')
        ->and(DB::table('fiscal_periods')
            ->where('fiscal_year_id', $this->fiscalYearId)
            ->where('status', 'open')
            ->count())->toBe(0)
        ->and(DB::table('fiscal_periods')
            ->where('fiscal_year_id', $this->fiscalYearId)
            ->whereNotNull('closed_at')
            ->count())->toBe(2);

    // Revenue and expense were cleared away and the 300 of profit landed in
    // retained earnings, leaving the two temporary accounts holding nothing.
    $temporary = DB::table('journal_lines')
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->whereIn('journal_lines.account_id', [$this->revenueAccountId, $expenseAccountId])
        ->whereIn('journals.status', ['posted', 'reversed'])
        ->selectRaw('SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
        ->value('net');

    expect((string) $temporary)->toBe('0.0000');

    $retained = DB::table('journal_lines')
        ->where('account_id', $retainedEarningsId)
        ->selectRaw('SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
        ->value('net');

    expect((string) $retained)->toBe('-300.0000');

    // And the statement agrees with the entry that closed it.
    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('equity.unclosed_result', '0.0000')
        ->assertJsonPath('equity.total', '300.0000')
        ->assertJsonPath('total_assets', '300.0000')
        ->assertJsonPath('balanced', true);
});

it('refuses to close a year that is already closed', function () {
    nxFiscalFinalPeriod($this->companyId, $this->fiscalYearId);

    $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    $this->postJournal($this->balancedJournal('JV-000100', '500.0000'));

    $expense = $this->makeJournal('JV-000101');
    $this->addLine($expense, 1, $expenseAccountId, '200.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($expense);

    $yearUlid = DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('ulid');

    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')->assertOk();

    // The request was well formed when it was sent; the books have simply moved
    // on since, so this collides with their state rather than being malformed.
    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')
        ->assertStatus(409)
        ->assertJsonPath('error', 'FiscalYearStateException');

    expect(DB::table('journals')->where('journal_type', 'closing')->count())->toBe(1);
});

it('refuses to close when the chart has no retained earnings account', function () {
    nxFiscalFinalPeriod($this->companyId, $this->fiscalYearId);

    // Deliberately no 3200 account: there is a result to close, and nowhere to
    // close it into.
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    $expense = $this->makeJournal('JV-000002');
    $this->addLine($expense, 1, $expenseAccountId, '200.0000', '0');
    $this->addLine($expense, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($expense);

    $yearUlid = DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('ulid');

    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')
        ->assertStatus(409)
        ->assertJsonPath('error', 'FiscalYearStateException');

    // Nothing half-happened: no closing journal and the year is still open.
    expect(DB::table('journals')->where('journal_type', 'closing')->count())->toBe(0)
        ->and(DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('status'))->toBe('open');
});

it('refuses a close from a member without the right', function () {
    nxFiscalFinalPeriod($this->companyId, $this->fiscalYearId);

    $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');

    $yearUlid = DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('ulid');

    // The auditor reads the books and never writes to them, so they must not be
    // able to declare the year finished.
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')->assertForbidden();

    expect(DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('status'))->toBe('open');
});

it('refuses a close from the accountant, because closing a year is the Owner\'s alone', function () {
    nxFiscalFinalPeriod($this->companyId, $this->fiscalYearId);

    $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');

    $yearUlid = DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('ulid');

    // The accountant keeps the books and may post any number of ordinary
    // journals, but closing a year turns them into a historical fact and is
    // reversible only by reopening it, so the right is kept for the Owner
    // alone.
    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')->assertForbidden();

    expect(DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('status'))->toBe('open');
});

it('answers not found for a year that belongs to another company', function () {
    $foreignYearUlid = (string) Str::ulid();
    $now = now();

    DB::table('fiscal_years')->insert([
        'ulid' => $foreignYearUlid,
        'company_id' => nxFiscalForeignCompanyId(),
        'name' => 'FY2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Sanctum::actingAs(nxFiscalMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The ULID is real, but not in the caller's company, so the answer is 404
    // rather than a 403 that would confirm the year exists elsewhere.
    $this->postJson('/api/v1/fiscal-years/'.$foreignYearUlid.'/close')->assertNotFound();
});
