<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Cash and bank accounts are backed by ledger accounts, so every assertion here
// goes through the real schema, triggers and period locks rather than around
// them.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // 1010 Bank Current Account is flagged is_cash, so it is a legal ledger
    // account for a bank account to be backed by. The name is the ledger
    // account id, not a bank_accounts row.
    $this->ledgerAccountId = $this->bankLedgerAccountId;

    // The other side of an opening balance. In real books this is capital,
    // retained earnings or an owner's current account.
    $this->equityAccountId = $this->makeAccount('3000', "Owner's Capital", 'equity', 'credit');

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxBankingMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxBankingForeignCompanyId(): int
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
 * A fully privileged member of a second tenant. Roles are created once per
 * company that exists when the seeder runs, so the foreign company has to be
 * inserted and the seeder re-run before anybody can hold a role inside it.
 */
function nxBankingForeignMember(string $role): User
{
    $companyId = nxBankingForeignCompanyId();

    test()->seed(RoleAndPermissionSeeder::class);

    return nxBankingMember($companyId, $role);
}

/**
 * The body of a bank account that is legal in every respect but the field a
 * given test is about to break.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxBankAccountBody(array $overrides = []): array
{
    return [
        'name' => 'CRDB Current Account',
        'account_id' => test()->ledgerAccountId,
        'account_type' => 'bank',
        'bank_name' => 'CRDB Bank',
        'account_number' => '0150-1234567-00',
        'opening_balance' => '1500.0000',
        'opening_balance_account_id' => test()->equityAccountId,
        'opening_date' => '2026-03-15',
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the cash and bank surface without a token', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/bank-accounts'],
    ['POST', '/api/v1/bank-accounts'],
    ['GET', '/api/v1/bank-accounts/'.'01JQZ0000000000000000000AB'],
]);

it('lets a cashier see the accounts without being able to open one', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/bank-accounts')->assertOk();

    // A new bank account is a new ledger account to post through, and inventing
    // one is how money leaves a set of books quietly.
    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody())->assertForbidden();
});

it('refuses the accounts to an auditor, who may only read the books', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // Reading which tills exist is a report-level right, so the auditor has it.
    $this->getJson('/api/v1/bank-accounts')->assertOk();
    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody())->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Opening an account
|--------------------------------------------------------------------------
*/

it('opens a bank account and brings its opening balance into the ledger', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $response = $this->postJson('/api/v1/bank-accounts', nxBankAccountBody())
        ->assertCreated()
        ->assertJsonPath('data.name', 'CRDB Current Account')
        ->assertJsonPath('data.account_id', $this->ledgerAccountId)
        ->assertJsonPath('data.ledger_account.code', '1010')
        ->assertJsonPath('data.account_type', 'bank')
        ->assertJsonPath('data.bank_name', 'CRDB Bank')
        ->assertJsonPath('data.currency_code', 'TZS')
        ->assertJsonPath('data.opening_balance', '1500.0000')
        ->assertJsonPath('data.status', 'active');

    $ulid = $response->json('data.ulid');

    // The opening balance is a posted journal, not a figure written on the row.
    $journal = DB::table('journals')->where('company_id', $this->companyId)->sole();

    expect($journal->journal_type)->toBe('opening')
        ->and($journal->status)->toBe('posted')
        ->and($journal->source_type)->toBe('App\\Models\\BankAccount')
        ->and(DB::table('journal_lines')->where('journal_id', $journal->id)->count())->toBe(2);

    // Dr the bank account 1500, Cr capital 1500.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->ledgerAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '1500.0000')
        ->assertJsonPath('meta.closing_balance', '1500.0000');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->equityAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_credit', '1500.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true)
        ->assertJsonPath('meta.total_debit', '1500.0000')
        ->assertJsonPath('meta.total_credit', '1500.0000');

    // The balance the screen shows is the ledger's own closing balance.
    $this->getJson('/api/v1/bank-accounts/'.$ulid)
        ->assertOk()
        ->assertJsonPath('data.ulid', $ulid)
        ->assertJsonPath('data.ledger_balance', '1500.0000')
        ->assertJsonPath('data.ledger_account.code', '1010');
});

it('opens a cash account with no opening balance and posts nothing', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $response = $this->postJson('/api/v1/bank-accounts', [
        'name' => 'Petty Cash Box',
        'account_id' => $this->cashAccountId,
        'account_type' => 'cash',
    ])
        ->assertCreated()
        ->assertJsonPath('data.account_type', 'cash')
        ->assertJsonPath('data.opening_balance', '0.0000');

    // Nothing to bring in, so nothing is written to the books at all.
    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0);

    $this->getJson('/api/v1/bank-accounts/'.$response->json('data.ulid'))
        ->assertOk()
        ->assertJsonPath('data.ledger_balance', '0.0000');
});

it('records an overdrawn bank account as a credit balance rather than a negative debit', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody([
        'name' => 'Overdrawn Current Account',
        'opening_balance' => '-400.0000',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.opening_balance', '-400.0000');

    // A journal line cannot carry a negative amount, so the two sides are
    // swapped instead: Cr the bank account, Dr capital.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->ledgerAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '0.0000')
        ->assertJsonPath('meta.period_credit', '400.0000')
        ->assertJsonPath('meta.closing_balance', '-400.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses an opening balance with no date to post it on', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $body = nxBankAccountBody();
    unset($body['opening_date']);

    $this->postJson('/api/v1/bank-accounts', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('opening_date');

    // Refused as a whole: no account row, no journal.
    expect(DB::table('bank_accounts')->where('company_id', $this->companyId)->count())->toBe(0)
        ->and(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses an opening balance with no contra account to post against', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $body = nxBankAccountBody();
    unset($body['opening_balance_account_id']);

    $this->postJson('/api/v1/bank-accounts', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('opening_balance_account_id');
});

it('refuses to link a bank account to a ledger account from another company', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreignAccountId = DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => nxBankingForeignCompanyId(),
        'code' => '1010',
        'name' => 'Bank Current Account',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => true,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Linking across tenants would post every later receipt into another set of
    // books, so the account has to belong to the caller's company.
    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody(['account_id' => $foreignAccountId]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_id');
});

it('refuses to link a bank account to a header account', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $headerId = $this->makeAccount('1090', 'Current Assets', 'asset', 'debit');
    DB::table('accounts')->where('id', $headerId)->update(['is_postable' => false]);

    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody(['account_id' => $headerId]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankAccountException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'header account'));
});

it('refuses to link a bank account to a ledger account that is not cash or bank', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Sales revenue is postable, but money does not sit in it.
    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody(['account_id' => $this->revenueAccountId]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankAccountException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not flagged as a cash or bank account'));
});

it('refuses two bank accounts with the same name in one company', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody())->assertCreated();

    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody([
        'account_id' => $this->cashAccountId,
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('refuses an account type outside the enum rather than ignoring it', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody(['account_type' => 'cryptocurrency']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('account_type');
});

it('answers not found for a bank account that belongs to another company', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $ulid = $this->postJson('/api/v1/bank-accounts', nxBankAccountBody())
        ->assertCreated()
        ->json('data.ulid');

    Sanctum::actingAs(nxBankingForeignMember(AccessMatrix::ROLE_OWNER));

    // The ULID is real, but not in the caller's company: 404 rather than a 403
    // that would confirm the account exists somewhere else.
    $this->getJson('/api/v1/bank-accounts/'.$ulid)->assertNotFound();

    // And the foreign tenant sees its own, empty, list.
    $this->getJson('/api/v1/bank-accounts')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

/*
|--------------------------------------------------------------------------
| Listing
|--------------------------------------------------------------------------
*/

it('pages the accounts in name order and filters them by type', function () {
    Sanctum::actingAs(nxBankingMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/bank-accounts', nxBankAccountBody(['name' => 'NMB Current Account']))->assertCreated();

    // The keys are left out rather than sent as null: `required_with` counts a
    // present-but-null field as present.
    $this->postJson('/api/v1/bank-accounts', Arr::except(nxBankAccountBody([
        'name' => 'Petty Cash Box',
        'account_id' => $this->cashAccountId,
        'account_type' => 'cash',
    ]), [
        'opening_balance',
        'opening_balance_account_id',
        'opening_date',
    ]))->assertCreated();

    $this->getJson('/api/v1/bank-accounts')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        // N comes before P.
        ->assertJsonPath('data.0.name', 'NMB Current Account')
        ->assertJsonPath('data.1.name', 'Petty Cash Box')
        ->assertJsonPath('data.1.account_type', 'cash');

    $this->getJson('/api/v1/bank-accounts?account_type=cash')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Petty Cash Box');

    $this->getJson('/api/v1/bank-accounts?search=NMB')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'NMB Current Account');
});
