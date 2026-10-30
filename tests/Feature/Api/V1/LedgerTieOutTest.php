<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Services\LedgerVerification;
use App\Jobs\RefreshLedgerBalances;
use App\Models\LedgerBalance;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The tie-out is a claim about three things agreeing — the statements, the
// ledger_balances snapshot and the raw journal_lines — and the draft/ledger
// distinction it rests on lives in the database, not in PHP. So it is exercised
// over a real schema.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxTieOutMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, used to prove a report cannot be pointed at books the
 * caller is not a member of.
 */
function nxTieOutForeignCompanyId(): int
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
 * Posts a two-line journal straight to the tables.
 *
 * Written as a plain function rather than through the fixture trait because the
 * tie-out calls it from contexts where the test instance is not in scope; it
 * follows the trait's order exactly — draft, lines, then posted — so the same
 * integrity triggers fire.
 */
function nxTieOutPostJournal(
    int $companyId,
    int $fiscalYearId,
    int $fiscalPeriodId,
    string $number,
    int $debitAccountId,
    int $creditAccountId,
    string $amount,
    ?string $postingDate = null,
    string $status = 'posted',
): int {
    $date = $postingDate ?? '2026-03-15';

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

    $lines = [
        [1, $debitAccountId, $amount, '0'],
        [2, $creditAccountId, '0', $amount],
    ];

    foreach ($lines as [$lineNo, $accountId, $debit, $credit]) {
        DB::table('journal_lines')->insert([
            'company_id' => $companyId,
            'journal_id' => $journalId,
            'line_no' => $lineNo,
            'account_id' => $accountId,
            'currency_code' => 'TZS',
            'posting_date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('journals')->where('id', $journalId)->update([
        'status' => $status,
        'posted_at' => $status === 'posted' ? now() : null,
        'updated_at' => now(),
    ]);

    return $journalId;
}

/**
 * The last period of the fiscal year, so the year-end close has somewhere to
 * date its closing journal. makeFixtures() installs March only.
 */
function nxTieOutFinalPeriod(int $companyId, int $fiscalYearId): int
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

/**
 * Debit minus credit for every posted line whose account is of the given type,
 * summed in MySQL.
 *
 * This is the tie-out's ground truth: it reads nothing the application wrote,
 * only journal_lines joined to journals and accounts, so a report that agreed
 * with itself but not with the ledger would still be caught.
 */
function nxTieOutRawByType(int $companyId, string $type): string
{
    return (string) DB::table('journal_lines')
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_lines.company_id', $companyId)
        ->where('accounts.account_type', $type)
        ->whereIn('journals.status', ['posted', 'reversed'])
        ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) AS net')
        ->value('net');
}

/**
 * The two sides every posted line in the company comes to, as raw strings.
 *
 * @return array{0: string, 1: string}
 */
function nxTieOutRawTotals(int $companyId): array
{
    $row = DB::table('journal_lines')
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('journal_lines.company_id', $companyId)
        ->whereIn('journals.status', ['posted', 'reversed'])
        ->selectRaw(
            'COALESCE(SUM(journal_lines.debit), 0) AS total_debit,'
            .' COALESCE(SUM(journal_lines.credit), 0) AS total_credit'
        )
        ->first();

    return [(string) $row->total_debit, (string) $row->total_credit];
}

/*
|--------------------------------------------------------------------------
| Statements agree with the raw ledger
|--------------------------------------------------------------------------
*/

it('balances assets against liabilities and equity, and the trial balance on both sides', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    // 500 of revenue and 200 of expense, both inside March 2026.
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    // 300 of assets: 500 in, less 200 out. No equity was put in and nothing has
    // been closed, so the other side is the unclosed result and nothing else.
    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('total_assets', '300.0000')
        ->assertJsonPath('total_liabilities_and_equity', '300.0000')
        ->assertJsonPath('balanced', true);

    // Raw ledger: assets came to 300, and liabilities plus equity less the
    // still-open temporary accounts have to come to the same figure, because
    // every posted journal balanced when it was posted.
    $assets = Money::of(nxTieOutRawByType($this->companyId, 'asset'));
    $owedAndHeld = Money::of(nxTieOutRawByType($this->companyId, 'liability'))
        ->plus(Money::of(nxTieOutRawByType($this->companyId, 'equity')))
        ->minus(Money::of(nxTieOutRawByType($this->companyId, 'revenue')))
        ->minus(Money::of(nxTieOutRawByType($this->companyId, 'expense')));

    expect($assets->toDecimal())->toBe('300.0000')
        ->and($owedAndHeld->equals($assets))->toBeTrue();

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true)
        ->assertJsonPath('meta.total_debit', '700.0000')
        ->assertJsonPath('meta.total_credit', '700.0000');
});

it('reports profit equal to revenue less expense as the raw lines hold it', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')
        ->assertOk()
        ->assertJsonPath('revenue.total', '500.0000')
        ->assertJsonPath('expense.total', '200.0000')
        ->assertJsonPath('net_profit', '300.0000');

    // Revenue is credit-normal, so its own side is the negated ledger figure.
    $revenue = Money::of(nxTieOutRawByType($this->companyId, 'revenue'))->negated();
    $expense = Money::of(nxTieOutRawByType($this->companyId, 'expense'));

    expect($revenue->minus($expense)->toDecimal())->toBe('300.0000');
});

it('leaves a draft journal out of the statements and the raw tie-out alike', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    // A draft's lines are already physically in journal_lines. They have not
    // reached the ledger, so nothing may count them — neither the statement nor
    // the raw sums it is checked against.
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000003', $this->cashAccountId, $this->revenueAccountId, '900.0000', null, 'draft');

    $this->getJson('/api/v1/reports/profit-and-loss')
        ->assertOk()
        ->assertJsonPath('net_profit', '300.0000');

    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('total_assets', '300.0000');

    [$debit, $credit] = nxTieOutRawTotals($this->companyId);

    expect($debit)->toBe('700.0000')
        ->and($credit)->toBe('700.0000');
});

it('reads the same year before and after it is closed', function () {
    nxTieOutFinalPeriod($this->companyId, $this->fiscalYearId);

    $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    // Numbered well clear of JV-000001: the close draws its own journal number
    // from the same gapless sequence, which starts at one.
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000100', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000101', $expenseAccountId, $this->cashAccountId, '200.0000');

    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $yearUlid = DB::table('fiscal_years')->where('id', $this->fiscalYearId)->value('ulid');

    $before = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')
        ->assertOk()
        ->json();

    $this->postJson('/api/v1/fiscal-years/'.$yearUlid.'/close')->assertOk();

    // The closing entry carries the closed year's own last day as its posting
    // date, so it falls inside this very window. The statement must read the
    // year it closed as it stood, not as nil.
    $after = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')
        ->assertOk()
        ->json();

    expect($after)->toBe($before);

    // And the balance sheet agrees with the entry that closed it: the result has
    // left the temporary accounts and is read in equity instead.
    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('equity.unclosed_result', '0.0000')
        ->assertJsonPath('equity.total', '300.0000')
        ->assertJsonPath('balanced', true);
});

it('has balanced every journal it posted', function () {
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    $unbalanced = DB::table('journals')
        ->where('company_id', $this->companyId)
        ->whereIn('status', ['posted', 'reversed'])
        ->whereRaw('total_debit <> total_credit')
        ->count();

    expect($unbalanced)->toBe(0)
        ->and(DB::table('journals')
            ->where('company_id', $this->companyId)
            ->whereIn('status', ['posted', 'reversed'])
            ->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Snapshot agrees with the ledger
|--------------------------------------------------------------------------
*/

it('rebuilds the snapshot so its closing buckets agree with the raw lines', function () {
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    // Cash: 500 in less 200 out, so a 300 debit close.
    $cash = LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->cashAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->firstOrFail();

    expect($cash->closing_debit)->toBe('300.0000')
        ->and($cash->closing_credit)->toBe('0.0000');

    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The snapshot's implied balance, the trial balance's net and the raw lines
    // are three ways of asking the same question and have to answer alike.
    $net = Money::of($cash->closing_debit)->minus(Money::of($cash->closing_credit));

    $this->getJson('/api/v1/reports/trial-balance?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('data.0.code', '1000')
        ->assertJsonPath('data.0.net', '300.0000');

    expect($net->toDecimal())->toBe(nxTieOutRawByType($this->companyId, 'asset'));
});

it('passes the verification when the snapshot agrees with the ledger', function () {
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    $result = app(LedgerVerification::class)->verify($this->companyId);

    expect($result['consistent'])->toBeTrue()
        ->and($result['divergences'])->toBe([])
        ->and($result['periods_checked'])->toBe(1)
        // Cash, revenue and the expense account all moved.
        ->and($result['snapshots_checked'])->toBe(3);
});

it('reports a divergence when a snapshot row has drifted from the ledger', function () {
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    // Somebody edited the cache by hand, or a bug wrote a wrong figure. This is
    // what the check exists to catch.
    LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->cashAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->update(['closing_debit' => '999.0000']);

    $result = app(LedgerVerification::class)->verify($this->companyId);

    expect($result['consistent'])->toBeFalse();

    $checks = array_column($result['divergences'], 'check');

    // Caught twice over: once against the calculator the writer uses, once
    // against the balance the reports read off the ledger.
    expect($checks)->toContain('closing_debit')
        ->and($checks)->toContain('closing_net');
});

it('does not call a period with no snapshot a divergence', function () {
    // A period that was never snapshotted is not a wrong snapshot. The company
    // has a March period and a December one, and neither has been built.
    nxTieOutFinalPeriod($this->companyId, $this->fiscalYearId);

    $result = app(LedgerVerification::class)->verify($this->companyId);

    expect($result['consistent'])->toBeTrue()
        ->and($result['divergences'])->toBe([])
        ->and($result['periods_checked'])->toBe(2)
        ->and($result['snapshots_checked'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The verification command
|--------------------------------------------------------------------------
*/

it('exits cleanly when the snapshot agrees with the ledger', function () {
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    $this->artisan('ledger:verify', ['--company' => $this->companyId])
        ->assertExitCode(0);

    expect(OutboxEvent::query()->where('event_type', 'ledger.verification_failed')->count())->toBe(0);
});

it('fails, alerts once and does not pile up alerts while one is outstanding', function () {
    $expenseAccountId = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $expenseAccountId, $this->cashAccountId, '200.0000');

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->cashAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->update(['closing_debit' => '999.0000']);

    // A non-zero exit code is how a nightly run nobody watched still becomes
    // visible, and the outbox row is what survives the process that raised it.
    $this->artisan('ledger:verify', ['--company' => $this->companyId])
        ->assertExitCode(1);

    $event = OutboxEvent::query()->where('event_type', 'ledger.verification_failed')->sole();

    expect($event->payload['divergence_count'])->toBeGreaterThan(0)
        ->and($event->payload['company_id'])->toBe($this->companyId);

    // Still outstanding, so a second run must not add a second alert.
    $this->artisan('ledger:verify', ['--company' => $this->companyId])
        ->assertExitCode(1);

    expect(OutboxEvent::query()->where('event_type', 'ledger.verification_failed')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Comparatives
|--------------------------------------------------------------------------
*/

it('adds a prior column and a variance to the profit and loss', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // March window: the fixtures' usual 2026-03-15 posting.
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');

    // The window of equal length immediately before March opens on 2026-01-29,
    // so a February posting lands in the prior column and nowhere else.
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $this->cashAccountId, $this->revenueAccountId, '100.0000', '2026-02-10');

    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31&compare=1')
        ->assertOk()
        ->assertJsonPath('revenue.total', '500.0000')
        ->assertJsonPath('comparison.revenue.total', '100.0000')
        ->assertJsonPath('variance.revenue', '400.0000')
        ->assertJsonPath('comparison.from', '2026-01-29')
        ->assertJsonPath('comparison.to', '2026-02-28');

    // Without the flag the report is single-column, so the extra figures are
    // absent rather than silently computed.
    $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonPath('comparison', null)
        ->assertJsonPath('variance', null);
});

it('reads the balance sheet prior column as at the prior period end', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The 500 lands in May, after the March period closes; only the 100 is on
    // the books by 2026-03-31, so the prior column has to differ from today.
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000', '2026-05-15');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $this->cashAccountId, $this->revenueAccountId, '100.0000', '2026-02-10');

    // The latest period closing before June ends on 2026-03-31, and only the
    // February posting stood by then.
    $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-06-30&compare=1')
        ->assertOk()
        ->assertJsonPath('total_assets', '600.0000')
        ->assertJsonPath('comparison.as_of', '2026-03-31')
        ->assertJsonPath('comparison.total_assets', '100.0000')
        ->assertJsonPath('comparison.balanced', true)
        ->assertJsonPath('variance.assets', '500.0000');
});

it('adds a prior column and a variance to the trial balance totals', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000001', $this->cashAccountId, $this->revenueAccountId, '500.0000');
    nxTieOutPostJournal($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-000002', $this->cashAccountId, $this->revenueAccountId, '100.0000', '2026-02-10');

    $this->getJson('/api/v1/reports/trial-balance?from=2026-03-01&to=2026-03-31&compare=1')
        ->assertOk()
        ->assertJsonPath('meta.total_debit', '500.0000')
        ->assertJsonPath('meta.comparison.total_debit', '100.0000')
        ->assertJsonPath('meta.comparison.to', '2026-02-28')
        ->assertJsonPath('meta.variance.total_debit', '400.0000');
});

/*
|--------------------------------------------------------------------------
| The verification endpoint
|--------------------------------------------------------------------------
*/

it('refuses the ledger verification without a token', function () {
    $this->getJson('/api/v1/reports/ledger-verification')->assertUnauthorized();
});

it('lets an auditor ask whether the snapshot agrees with the ledger', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/ledger-verification')
        ->assertOk()
        ->assertJsonPath('consistent', true)
        ->assertJsonPath('snapshots_checked', 0);
});

it('refuses the ledger verification for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxTieOutForeignCompanyId())
        ->getJson('/api/v1/reports/ledger-verification')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

it('answers not found for a fiscal period belonging to another company', function () {
    $now = now();
    $foreignCompanyId = nxTieOutForeignCompanyId();

    $foreignYearId = DB::table('fiscal_years')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $foreignCompanyId,
        'name' => 'FY2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $foreignUlid = (string) Str::ulid();

    DB::table('fiscal_periods')->insert([
        'ulid' => $foreignUlid,
        'company_id' => $foreignCompanyId,
        'fiscal_year_id' => $foreignYearId,
        'period_no' => 1,
        'name' => 'January 2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Sanctum::actingAs(nxTieOutMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The ULID is real, but not in the caller's company, so the answer is 404
    // rather than a 403 that would confirm the period exists elsewhere.
    $this->getJson('/api/v1/reports/ledger-verification?fiscal_period='.$foreignUlid)
        ->assertNotFound();
});
