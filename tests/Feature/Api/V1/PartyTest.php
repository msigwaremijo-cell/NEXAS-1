<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A party is master data that documents already issued point at, so the two
// halves of its surface — reading one record and amending it — are exercised
// over the real schema, and every lookup is proved to be scoped to the caller's
// own company rather than merely to the table.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxPartyMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, so nothing in the caller's company can be reached by naming
 * something in another one.
 */
function nxPartyForeignCompanyId(): int
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
 * A customer row written directly, so a test can pin exactly the fields it
 * asserts on without going through the create endpoint first.
 *
 * @return array{id: int, ulid: string}
 */
function nxPartyCustomer(int $companyId, int $receivableAccountId, string $code = 'C-001'): array
{
    $ulid = (string) Str::ulid();

    return [
        'id' => DB::table('customers')->insertGetId([
            'ulid' => $ulid,
            'company_id' => $companyId,
            'code' => $code,
            'name' => 'Acme Limited',
            'currency_code' => 'TZS',
            'receivable_account_id' => $receivableAccountId,
            'credit_limit' => '0.0000',
            'payment_terms_days' => 30,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'ulid' => $ulid,
    ];
}

/**
 * @return array{id: int, ulid: string}
 */
function nxPartySupplier(int $companyId, int $payableAccountId, string $code = 'S-001'): array
{
    $ulid = (string) Str::ulid();

    return [
        'id' => DB::table('suppliers')->insertGetId([
            'ulid' => $ulid,
            'company_id' => $companyId,
            'code' => $code,
            'name' => 'Supplyco Limited',
            'currency_code' => 'TZS',
            'payable_account_id' => $payableAccountId,
            'credit_limit' => '0.0000',
            'payment_terms_days' => 30,
            'withholding_agent' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'ulid' => $ulid,
    ];
}

/**
 * A ledger account belonging to another tenant.
 */
function nxPartyForeignAccountId(string $code = '1100'): int
{
    $now = now();

    return DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => nxPartyForeignCompanyId(),
        'code' => $code,
        'name' => 'Accounts Receivable',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/*
|--------------------------------------------------------------------------
| Reading one party
|--------------------------------------------------------------------------
*/

it('reads a customer by its public id', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId);

    $this->getJson('/api/v1/customers/'.$customer['ulid'])
        ->assertOk()
        ->assertJsonPath('data.id', $customer['id'])
        ->assertJsonPath('data.ulid', $customer['ulid'])
        ->assertJsonPath('data.code', 'C-001')
        ->assertJsonPath('data.receivable_account_id', $this->receivableAccountId)
        ->assertJsonPath('data.currency_code', 'TZS')
        ->assertJsonPath('data.payment_terms_days', 30)
        ->assertJsonPath('data.status', 'active');
});

it('reads a supplier by its public id', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $supplier = nxPartySupplier($this->companyId, $this->payableAccountId);

    $this->getJson('/api/v1/suppliers/'.$supplier['ulid'])
        ->assertOk()
        ->assertJsonPath('data.code', 'S-001')
        ->assertJsonPath('data.payable_account_id', $this->payableAccountId)
        ->assertJsonPath('data.withholding_agent', false);
});

it('refuses to read a party without a token', function () {
    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId);

    $this->getJson('/api/v1/customers/'.$customer['ulid'])->assertUnauthorized();
});

it('answers not found for a party of another company', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customer = nxPartyCustomer(nxPartyForeignCompanyId(), $this->receivableAccountId);
    $supplier = nxPartySupplier(nxPartyForeignCompanyId(), $this->payableAccountId);

    // Both ids exist; neither is in the caller's books, so both are a 404
    // rather than an oracle that would confirm the rows are there.
    $this->getJson('/api/v1/customers/'.$customer['ulid'])->assertNotFound();
    $this->patchJson('/api/v1/customers/'.$customer['ulid'], ['name' => 'Taken Over'])->assertNotFound();
    $this->getJson('/api/v1/suppliers/'.$supplier['ulid'])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Amending a party
|--------------------------------------------------------------------------
*/

it('amends a customer without demanding the fields it is not changing', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId);

    // Only the status and the phone number are sent: everything else the
    // record already carries is left alone.
    $this->patchJson('/api/v1/customers/'.$customer['ulid'], ['status' => 'inactive', 'phone' => '0755 000 111'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.phone', '0755 000 111')
        ->assertJsonPath('data.code', 'C-001')
        ->assertJsonPath('data.receivable_account_id', $this->receivableAccountId);

    expect(DB::table('customers')->where('id', $customer['id'])->value('status'))->toBe('inactive');
});

it('lets a customer keep the code it already has', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId, 'C-001');

    // The uniqueness check ignores the row being amended, so resending a
    // record's own code is not a collision with itself.
    $this->patchJson('/api/v1/customers/'.$customer['ulid'], ['code' => 'C-001', 'name' => 'Acme Trading'])
        ->assertOk()
        ->assertJsonPath('data.code', 'C-001')
        ->assertJsonPath('data.name', 'Acme Trading');
});

it('refuses a code another customer in the company already uses', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    nxPartyCustomer($this->companyId, $this->receivableAccountId, 'C-001');
    $second = nxPartyCustomer($this->companyId, $this->receivableAccountId, 'C-002');

    $this->patchJson('/api/v1/customers/'.$second['ulid'], ['code' => 'C-001'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('refuses to move a customer onto another company ledger account', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId);

    // The account exists, but not in the caller's books. Accepting it would
    // send every future invoice for this customer into another tenant's ledger.
    $this->patchJson('/api/v1/customers/'.$customer['ulid'], [
        'receivable_account_id' => nxPartyForeignAccountId(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('receivable_account_id');
});

it('amends a supplier, including its withholding flag', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $supplier = nxPartySupplier($this->companyId, $this->payableAccountId);

    $this->patchJson('/api/v1/suppliers/'.$supplier['ulid'], ['withholding_agent' => true, 'payment_terms_days' => 45])
        ->assertOk()
        ->assertJsonPath('data.withholding_agent', true)
        ->assertJsonPath('data.payment_terms_days', 45)
        ->assertJsonPath('data.code', 'S-001');
});

it('refuses to move a supplier onto another company payable account', function () {
    Sanctum::actingAs(nxPartyMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $supplier = nxPartySupplier($this->companyId, $this->payableAccountId);

    $this->patchJson('/api/v1/suppliers/'.$supplier['ulid'], [
        'payable_account_id' => nxPartyForeignAccountId('2000'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payable_account_id');
});

/*
|--------------------------------------------------------------------------
| Who may read and who may amend
|--------------------------------------------------------------------------
*/

it('lets a member who may read a party look one up', function (string $role) {
    Sanctum::actingAs(nxPartyMember($this->companyId, $role));

    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId);
    $supplier = nxPartySupplier($this->companyId, $this->payableAccountId);

    $this->getJson('/api/v1/customers/'.$customer['ulid'])->assertOk();
    $this->getJson('/api/v1/suppliers/'.$supplier['ulid'])->assertOk();
})->with([
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_INVENTORY_MANAGER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_READ_ONLY,
]);

it('refuses an amendment to every role that may not maintain the list', function (string $role) {
    Sanctum::actingAs(nxPartyMember($this->companyId, $role));

    $customer = nxPartyCustomer($this->companyId, $this->receivableAccountId);

    $this->patchJson('/api/v1/customers/'.$customer['ulid'], ['name' => 'Renamed'])->assertForbidden();

    // Refused before anything was written, not after.
    expect(DB::table('customers')->where('id', $customer['id'])->value('name'))->toBe('Acme Limited');
})->with([
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_INVENTORY_MANAGER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_READ_ONLY,
]);

it('refuses a supplier amendment to a role that may only read', function (string $role) {
    Sanctum::actingAs(nxPartyMember($this->companyId, $role));

    $supplier = nxPartySupplier($this->companyId, $this->payableAccountId);

    $this->patchJson('/api/v1/suppliers/'.$supplier['ulid'], ['name' => 'Renamed'])->assertForbidden();

    expect(DB::table('suppliers')->where('id', $supplier['id'])->value('name'))->toBe('Supplyco Limited');
})->with([
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_READ_ONLY,
]);
