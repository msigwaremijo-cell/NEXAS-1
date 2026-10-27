<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Jobs\RefreshLedgerBalances;
use App\Models\LedgerBalance;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The reports are read straight off MySQL, so they are exercised over a real
// schema: the draft/ledger distinction they depend on lives in the triggers,
// not in PHP.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxReportMember(int $companyId, string $role): User
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
function nxReportForeignCompanyId(): int
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

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the read surface without a token', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
})->with([
    '/api/v1/accounts',
    '/api/v1/reports/general-ledger?account_id=1',
    '/api/v1/reports/trial-balance',
]);

it('lets an auditor read the accounts and both reports', function () {
    // The auditor holds report.view and nothing else. Reading is exactly what
    // an audit needs, so all three endpoints have to answer.
    $auditor = nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    Sanctum::actingAs($auditor);

    $this->getJson('/api/v1/accounts')->assertOk();

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId)->assertOk();

    $this->getJson('/api/v1/reports/trial-balance')->assertOk();
});

it('refuses a report for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxReportForeignCompanyId())
        ->getJson('/api/v1/reports/trial-balance')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

/*
|--------------------------------------------------------------------------
| Chart of accounts
|--------------------------------------------------------------------------
*/

it('pages the chart of accounts in code order', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The fixtures install 1000 Cash on Hand and 4000 Sales Revenue.
    $this->getJson('/api/v1/accounts?per_page=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', '1000')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/v1/accounts?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.code', '4000')
        ->assertJsonPath('meta.current_page', 2);
});

it('filters the chart of accounts by type, postability and search', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // A header account: it rolls its children up and must never be posted to.
    $headerId = $this->makeAccount('4000-H', 'Revenue Header', 'revenue', 'credit');

    DB::table('accounts')->where('id', $headerId)->update(['is_postable' => false]);

    $this->getJson('/api/v1/accounts?type=revenue')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.code', '4000')
        ->assertJsonPath('data.1.code', '4000-H');

    $this->getJson('/api/v1/accounts?type=revenue&postable=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', '4000');

    $this->getJson('/api/v1/accounts?search=Cash')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', '1000');
});

it('rejects an account type outside the enum rather than ignoring it', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/accounts?type=banana')
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('keeps a soft deleted account out of the chart', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    DB::table('accounts')
        ->where('id', $this->revenueAccountId)
        ->update(['deleted_at' => now()]);

    $this->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', '1000');
});

/*
|--------------------------------------------------------------------------
| General ledger
|--------------------------------------------------------------------------
*/

it('carries the running balance across a page boundary', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->postJournal($this->balancedJournal('JV-000001', '100.0000'));
    $this->postJournal($this->balancedJournal('JV-000002', '50.0000'));

    // Money out of cash: revenue debited, cash credited.
    $payment = $this->makeJournal('JV-000003');
    $this->addLine($payment, 1, $this->revenueAccountId, '20.0000', '0');
    $this->addLine($payment, 2, $this->cashAccountId, '0', '20.0000');
    $this->postJournal($payment);

    $uri = '/api/v1/reports/general-ledger?account_id='.$this->cashAccountId.'&per_page=2';

    $this->getJson($uri)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.journal_no', 'JV-000001')
        ->assertJsonPath('data.0.debit', '100.0000')
        ->assertJsonPath('data.0.running_balance', '100.0000')
        ->assertJsonPath('data.1.running_balance', '150.0000')
        ->assertJsonPath('meta.account_code', '1000')
        ->assertJsonPath('meta.opening_balance', '0.0000')
        ->assertJsonPath('meta.brought_forward', '0.0000')
        ->assertJsonPath('meta.period_debit', '150.0000')
        ->assertJsonPath('meta.period_credit', '20.0000')
        ->assertJsonPath('meta.closing_balance', '130.0000')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);

    // Page two has to open where page one stopped: 150 in, less the 20 paid out.
    $this->getJson($uri.'&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.credit', '20.0000')
        ->assertJsonPath('data.0.running_balance', '130.0000')
        ->assertJsonPath('meta.brought_forward', '150.0000');
});

it('keeps a draft journal out of the ledger and the trial balance', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->postJournal($this->balancedJournal('JV-000001', '100.0000'));

    // A draft's lines are already physically in journal_lines. They have not
    // reached the ledger, so nothing below may count them.
    $this->balancedJournal('JV-000002', '900.0000');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId)
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.period_debit', '100.0000')
        ->assertJsonPath('meta.closing_balance', '100.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.total_debit', '100.0000')
        ->assertJsonPath('meta.total_credit', '100.0000');
});

it('keeps a reversed journal in the ledger and nets it off with its reversal', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $original = $this->balancedJournal('JV-000001', '100.0000');
    $this->postJournal($original);

    // ReverseJournal posts a mirror-image journal and then records the link on
    // the original, which moves it to 'reversed'. Both sets of lines stay in
    // the ledger for ever; dropping the original would leave the correction
    // standing on its own and turn a nil effect into a 100 debit.
    $reversal = $this->makeJournal('JV-000002');
    $this->addLine($reversal, 1, $this->revenueAccountId, '100.0000', '0');
    $this->addLine($reversal, 2, $this->cashAccountId, '0', '100.0000');
    $this->postJournal($reversal);

    DB::table('journals')->where('id', $original)->update([
        'status' => JournalStatus::Reversed->value,
        'reversed_by_journal_id' => $reversal,
    ]);

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId)
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.period_debit', '100.0000')
        ->assertJsonPath('meta.period_credit', '100.0000')
        ->assertJsonPath('meta.closing_balance', '0.0000')
        ->assertJsonPath('data.1.running_balance', '0.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.total_debit', '200.0000')
        ->assertJsonPath('meta.total_credit', '200.0000')
        ->assertJsonPath('meta.balanced', true);
});

it('answers not found for an account that belongs to another company', function () {
    $now = now();

    $foreignAccountId = DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => nxReportForeignCompanyId(),
        'code' => '1000',
        'name' => 'Cash on Hand',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // The id is real, but not in the caller's company, so the answer is 404
    // rather than a 403 that would confirm the account exists elsewhere.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$foreignAccountId)
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Trial balance
|--------------------------------------------------------------------------
*/

it('totals the trial balance to zero on both sides', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->postJournal($this->balancedJournal('JV-000001', '100.0000'));
    $this->postJournal($this->balancedJournal('JV-000002', '250.5000'));

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.code', '1000')
        ->assertJsonPath('data.0.debit', '350.5000')
        ->assertJsonPath('data.0.credit', '0.0000')
        ->assertJsonPath('data.0.net', '350.5000')
        ->assertJsonPath('data.1.code', '4000')
        ->assertJsonPath('data.1.credit', '350.5000')
        ->assertJsonPath('data.1.net', '-350.5000')
        ->assertJsonPath('meta.total_debit', '350.5000')
        ->assertJsonPath('meta.total_credit', '350.5000')
        ->assertJsonPath('meta.balanced', true);
});

it('leaves an account out of the trial balance when it did not move', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->postJournal($this->balancedJournal('JV-000001', '100.0000'));

    // The range opens after the only posting, so nothing moved inside it.
    $this->getJson('/api/v1/reports/trial-balance?from=2026-04-01&to=2026-04-30')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.total_debit', '0.0000')
        ->assertJsonPath('meta.total_credit', '0.0000');
});

it('refuses a range that runs backwards', function () {
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/trial-balance?from=2026-03-31&to=2026-03-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

/*
|--------------------------------------------------------------------------
| Ledger balance snapshot
|--------------------------------------------------------------------------
*/

it('rebuilds the ledger snapshot so that it agrees with the ledger itself', function () {
    $this->postJournal($this->balancedJournal('JV-000001', '100.0000'));
    $this->postJournal($this->balancedJournal('JV-000002', '50.0000'));

    // Present in journal_lines, absent from the ledger, so absent here too.
    $this->balancedJournal('JV-000003', '900.0000');

    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    $cash = LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->cashAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->firstOrFail();

    expect($cash->opening_debit)->toBe('0.0000')
        ->and($cash->opening_credit)->toBe('0.0000')
        ->and($cash->period_debit)->toBe('150.0000')
        ->and($cash->period_credit)->toBe('0.0000')
        ->and($cash->closing_debit)->toBe('150.0000')
        ->and($cash->closing_credit)->toBe('0.0000')
        ->and($cash->refreshed_at)->not->toBeNull();

    $revenue = LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('account_id', $this->revenueAccountId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->firstOrFail();

    // Credit-normal movement, stored as a gross credit bucket: chk_ledger_
    // balances_non_negative rejects a negative money column outright.
    expect($revenue->period_debit)->toBe('0.0000')
        ->and($revenue->period_credit)->toBe('150.0000')
        ->and($revenue->closing_debit)->toBe('0.0000')
        ->and($revenue->closing_credit)->toBe('150.0000');

    // The snapshot has to say the same thing the report reads off the ledger.
    Sanctum::actingAs(nxReportMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $closing = $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId)
        ->json('meta.closing_balance');

    expect($closing)->toBe($cash->closing_debit);

    // And running it again has to be a rebuild, not an accumulation.
    (new RefreshLedgerBalances($this->companyId, $this->fiscalPeriodId))->handle();

    expect(LedgerBalance::query()
        ->where('company_id', $this->companyId)
        ->where('fiscal_period_id', $this->fiscalPeriodId)
        ->count())->toBe(2);
});
