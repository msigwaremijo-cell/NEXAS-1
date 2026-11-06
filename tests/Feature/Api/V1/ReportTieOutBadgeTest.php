<?php

use App\Domain\Access\AccessMatrix;
use App\Jobs\RefreshLedgerBalances;
use App\Models\LedgerBalance;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

/*
|--------------------------------------------------------------------------
| What the "Ties to ledger" badge is allowed to say
|--------------------------------------------------------------------------
|
| The badge on a report has three answers, and each one is a reading of this
| endpoint rather than a claim of its own:
|
|   Verified    — balances were read back and every one agreed.
|   Not checked — nothing was read back, so nothing was compared.
|   Mismatch    — balances were read back and some disagreed.
|
| The trap is the middle one. `consistent` is true whenever nothing
| contradicted the ledger, and nothing contradicts it when there is nothing to
| compare, so an empty snapshot answers `consistent: true` exactly like a
| healthy one. The badge reads `snapshots_checked` first for that reason, and
| these tests pin the two figures together so a change to either cannot make
| "nothing was checked" look like a pass.
|
| The states themselves are chosen in the portal's JavaScript, which this suite
| does not run; what is pinned here is the shape and the arithmetic of the three
| answers it chooses between.
*/
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company holding one company-scoped role.
 */
function nxTieBadgeMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * Posts a two-line journal straight to the tables, draft first and then posted,
 * so the integrity triggers fire in the order the application writes them.
 */
function nxTieBadgePostJournal(
    int $companyId,
    int $fiscalYearId,
    int $fiscalPeriodId,
    string $number,
    int $debitAccountId,
    int $creditAccountId,
    string $amount,
): int {
    $date = '2026-03-15';

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

    foreach ([[1, $debitAccountId, $amount, '0'], [2, $creditAccountId, '0', $amount]] as [$lineNo, $accountId, $debit, $credit]) {
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
        'status' => 'posted',
        'posted_at' => now(),
        'updated_at' => now(),
    ]);

    return $journalId;
}

/**
 * The two journals every test in this file is built on: 500 in, 200 out.
 */
function nxTieBadgeTradingJournals(int $companyId, int $fiscalYearId, int $fiscalPeriodId, int $cash, int $revenue, int $expense): void
{
    nxTieBadgePostJournal($companyId, $fiscalYearId, $fiscalPeriodId, 'JV-000001', $cash, $revenue, '500.0000');
    nxTieBadgePostJournal($companyId, $fiscalYearId, $fiscalPeriodId, 'JV-000002', $expense, $cash, '200.0000');
}

/*
|--------------------------------------------------------------------------
| Nothing checked is not a pass
|--------------------------------------------------------------------------
*/

it('answers consistent, with nothing checked, when no balance has ever been cached', function () {
    $expense = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');
    nxTieBadgeTradingJournals($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, $this->cashAccountId, $this->revenueAccountId, $expense);

    Sanctum::actingAs(nxTieBadgeMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // A company with real posted journals and no snapshot at all. The endpoint
    // is right to say nothing contradicted the ledger — and the badge must not
    // read that as "the report ties to the ledger", which is why the reader of
    // this payload looks at snapshots_checked before it looks at consistent.
    $this->getJson('/api/v1/reports/ledger-verification')
        ->assertOk()
        ->assertJsonPath('consistent', true)
        ->assertJsonPath('snapshots_checked', 0)
        ->assertJsonPath('accounts_checked', 0)
        ->assertJsonPath('periods_checked', 1)
        ->assertJsonPath('divergences', []);
});

it('answers consistent, with nothing checked, when a second period holds no cache either', function () {
    // A period that was never snapshotted is not a wrong snapshot, so a company
    // with two open periods and no cache reads exactly like one with none: the
    // badge has nothing to turn green about in either case.
    nxTieBadgeFinalPeriod($this->companyId, $this->fiscalYearId);

    Sanctum::actingAs(nxTieBadgeMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/ledger-verification')
        ->assertOk()
        ->assertJsonPath('consistent', true)
        ->assertJsonPath('snapshots_checked', 0)
        ->assertJsonPath('periods_checked', 2);
});

/**
 * The last period of the fiscal year, so a second open period exists.
 */
function nxTieBadgeFinalPeriod(int $companyId, int $fiscalYearId): int
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
| Verified: balances were read back and agreed
|--------------------------------------------------------------------------
*/

it('answers verified once the cached balances have been read back and agree', function () {
    $expense = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');
    nxTieBadgeTradingJournals($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, $this->cashAccountId, $this->revenueAccountId, $expense);

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    Sanctum::actingAs(nxTieBadgeMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // Cash, revenue and the expense account all moved, so three rows were
    // cached, read back and agreed. Only now may the badge be green.
    $this->getJson('/api/v1/reports/ledger-verification')
        ->assertOk()
        ->assertJsonPath('consistent', true)
        ->assertJsonPath('snapshots_checked', 3)
        ->assertJsonPath('accounts_checked', 3)
        ->assertJsonPath('divergences', []);
});

/*
|--------------------------------------------------------------------------
| Mismatch: the difference is what the badge shows
|--------------------------------------------------------------------------
*/

it('answers mismatch, with the figures and the difference, when a cached balance has drifted', function () {
    $expense = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');
    nxTieBadgeTradingJournals($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, $this->cashAccountId, $this->revenueAccountId, $expense);

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    // Cash closed at 300 after 500 in and 200 out. Something wrote 999 into the
    // cache instead, which is what this check exists to catch.
    LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->cashAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->update(['closing_debit' => '999.0000']);

    Sanctum::actingAs(nxTieBadgeMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $response = $this->getJson('/api/v1/reports/ledger-verification')
        ->assertOk()
        ->assertJsonPath('consistent', false)
        ->assertJsonPath('snapshots_checked', 3);

    $divergences = $response->json('divergences');

    expect($divergences)->not->toBe([]);

    // Every divergence carries both figures, because the badge shows both and
    // subtracts them. Without the pair there is nothing to show a difference
    // from, and a red badge with no figure would be a warning nobody can act on.
    foreach ($divergences as $divergence) {
        expect($divergence)->toHaveKeys(['check', 'snapshot', 'ledger', 'period', 'account_id']);
    }

    $drift = collect($divergences)->firstWhere('check', 'closing_debit');

    expect($drift)->not->toBeNull()
        ->and($drift['snapshot'])->toBe('999.0000')
        ->and($drift['ledger'])->toBe('300.0000');

    // The difference the badge prints: the ledger's figure less the cached one,
    // at the money scale, so 300.0000 - 999.0000 is exactly -699.0000 and not a
    // floating-point near miss.
    expect(sprintf('%.4f', (float) $drift['ledger'] - (float) $drift['snapshot']))->toBe('-699.0000');
});

it('catches the same drift twice over, so a single bad figure is never one disagreement only', function () {
    $expense = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');
    nxTieBadgeTradingJournals($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, $this->cashAccountId, $this->revenueAccountId, $expense);

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->cashAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->update(['closing_debit' => '999.0000']);

    Sanctum::actingAs(nxTieBadgeMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $checks = array_column($this->getJson('/api/v1/reports/ledger-verification')->json('divergences'), 'check');

    // Once against the calculator the writer uses, once against the balance the
    // reports read off the ledger. A badge that counted only the first would
    // under-report what is wrong.
    expect($checks)->toContain('closing_debit')
        ->and($checks)->toContain('closing_net');
});

/*
|--------------------------------------------------------------------------
| Whose books
|--------------------------------------------------------------------------
*/

it('refuses the check to a member with no role in the company', function () {
    // The badge is drawn from this endpoint, so a member who may not read it
    // must not be able to read the check either, whatever the window shows.
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/reports/ledger-verification')->assertForbidden();
});

it('refuses the check for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxTieBadgeMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxTieBadgeForeignCompanyId())
        ->getJson('/api/v1/reports/ledger-verification')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

/**
 * A second tenant, used to prove the check cannot be pointed at books the
 * caller is not a member of.
 */
function nxTieBadgeForeignCompanyId(): int
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
