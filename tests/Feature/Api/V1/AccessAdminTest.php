<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The authorisation matrix is enforced through real company-scoped roles, so
// every test here is exercised over the seeded roles and the team-scoped
// permission resolver rather than around them: a test that granted itself a
// role by hand would not notice a broken seeder.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // The roles are created once per company that exists when the seeder runs,
    // so it runs after the fixture company has been installed.
    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxAdminMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, used to prove that membership of one company is not
 * membership of another.
 */
function nxAdminForeignCompanyId(): int
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

function nxAdminSupplier(int $companyId, int $payableAccountId): int
{
    return DB::table('suppliers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => 'S-001',
        'name' => 'Kilimanjaro Supplies Limited',
        'currency_code' => 'TZS',
        'payable_account_id' => $payableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 45,
        'status' => 'active',
        'withholding_agent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function nxAdminBillBody(int $supplierId, int $expenseAccountId): array
{
    return [
        'supplier_id' => $supplierId,
        'bill_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Office stationery',
            'account_id' => $expenseAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function nxAdminJournalBody(int $debitAccountId, int $creditAccountId, string $amount = '250.0000'): array
{
    return [
        'posting_date' => '2026-03-15',
        'reference' => 'ACCESS-TEST-001',
        'memo' => 'Access matrix test entry',
        'lines' => [
            ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $creditAccountId, 'debit' => '0', 'credit' => $amount],
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| The matrix
|--------------------------------------------------------------------------
*/

it('grants each of the seven roles exactly the rights the matrix allows', function (string $role, array $expected) {
    Sanctum::actingAs(nxAdminMember($this->companyId, $role));

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.roles', [$role])
        // The list is written out rather than read back out of AccessMatrix: a
        // test that asks the matrix what it grants only proves it equals itself.
        ->assertJsonPath('user.permissions', function (array $permissions) use ($expected): bool {
            sort($permissions);
            sort($expected);

            return $permissions === $expected;
        });
})->with([
    AccessMatrix::ROLE_OWNER => [AccessMatrix::ROLE_OWNER, [
        AccessMatrix::PERMISSION_JOURNAL_VIEW,
        AccessMatrix::PERMISSION_JOURNAL_CREATE,
        AccessMatrix::PERMISSION_JOURNAL_POST,
        AccessMatrix::PERMISSION_JOURNAL_REVERSE,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_ACCOUNT_MANAGE,
        AccessMatrix::PERMISSION_USER_MANAGE,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_PARTY_MANAGE,
        AccessMatrix::PERMISSION_INVOICE_VIEW,
        AccessMatrix::PERMISSION_INVOICE_MANAGE,
        AccessMatrix::PERMISSION_INVOICE_ISSUE,
        AccessMatrix::PERMISSION_BILL_VIEW,
        AccessMatrix::PERMISSION_BILL_MANAGE,
        AccessMatrix::PERMISSION_BILL_APPROVE,
        AccessMatrix::PERMISSION_BILL_POST,
        AccessMatrix::PERMISSION_PAYMENT_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_RECORD,
        AccessMatrix::PERMISSION_BANK_VIEW,
        AccessMatrix::PERMISSION_BANK_MANAGE,
        AccessMatrix::PERMISSION_BANK_RECONCILE,
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_STOCK_MANAGE,
        AccessMatrix::PERMISSION_STOCK_POST,
        AccessMatrix::PERMISSION_COMPANY_MANAGE,
        AccessMatrix::PERMISSION_FISCAL_YEAR_CLOSE,
        AccessMatrix::PERMISSION_FISCAL_PERIOD_REOPEN,
        AccessMatrix::PERMISSION_AUDIT_VIEW,
        AccessMatrix::PERMISSION_BUDGET_VIEW,
        AccessMatrix::PERMISSION_BUDGET_MANAGE,
        AccessMatrix::PERMISSION_ASSET_VIEW,
        AccessMatrix::PERMISSION_ASSET_MANAGE,
        AccessMatrix::PERMISSION_ASSET_POST,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_MANAGE,
    ]],

    AccessMatrix::ROLE_ACCOUNTANT => [AccessMatrix::ROLE_ACCOUNTANT, [
        AccessMatrix::PERMISSION_JOURNAL_VIEW,
        AccessMatrix::PERMISSION_JOURNAL_CREATE,
        AccessMatrix::PERMISSION_JOURNAL_POST,
        AccessMatrix::PERMISSION_JOURNAL_REVERSE,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_ACCOUNT_MANAGE,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_PARTY_MANAGE,
        AccessMatrix::PERMISSION_INVOICE_VIEW,
        AccessMatrix::PERMISSION_INVOICE_MANAGE,
        AccessMatrix::PERMISSION_INVOICE_ISSUE,
        AccessMatrix::PERMISSION_BILL_VIEW,
        AccessMatrix::PERMISSION_BILL_MANAGE,
        AccessMatrix::PERMISSION_BILL_POST,
        AccessMatrix::PERMISSION_PAYMENT_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_RECORD,
        AccessMatrix::PERMISSION_BANK_VIEW,
        AccessMatrix::PERMISSION_BANK_MANAGE,
        AccessMatrix::PERMISSION_BANK_RECONCILE,
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_STOCK_MANAGE,
        AccessMatrix::PERMISSION_STOCK_POST,
        AccessMatrix::PERMISSION_COMPANY_MANAGE,
        AccessMatrix::PERMISSION_BUDGET_VIEW,
        AccessMatrix::PERMISSION_BUDGET_MANAGE,
        AccessMatrix::PERMISSION_ASSET_VIEW,
        AccessMatrix::PERMISSION_ASSET_MANAGE,
        AccessMatrix::PERMISSION_ASSET_POST,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_MANAGE,
    ]],

    AccessMatrix::ROLE_FINANCE_OFFICER => [AccessMatrix::ROLE_FINANCE_OFFICER, [
        AccessMatrix::PERMISSION_JOURNAL_VIEW,
        AccessMatrix::PERMISSION_JOURNAL_CREATE,
        AccessMatrix::PERMISSION_JOURNAL_POST,
        AccessMatrix::PERMISSION_JOURNAL_REVERSE,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_ACCOUNT_MANAGE,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_PARTY_MANAGE,
        AccessMatrix::PERMISSION_INVOICE_VIEW,
        AccessMatrix::PERMISSION_INVOICE_MANAGE,
        AccessMatrix::PERMISSION_INVOICE_ISSUE,
        AccessMatrix::PERMISSION_BILL_VIEW,
        AccessMatrix::PERMISSION_BILL_APPROVE,
        AccessMatrix::PERMISSION_BILL_POST,
        AccessMatrix::PERMISSION_PAYMENT_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_RECORD,
        AccessMatrix::PERMISSION_BANK_VIEW,
        AccessMatrix::PERMISSION_BANK_MANAGE,
        AccessMatrix::PERMISSION_BANK_RECONCILE,
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_STOCK_MANAGE,
        AccessMatrix::PERMISSION_STOCK_POST,
        AccessMatrix::PERMISSION_BUDGET_VIEW,
        AccessMatrix::PERMISSION_ASSET_VIEW,
        AccessMatrix::PERMISSION_ASSET_MANAGE,
        AccessMatrix::PERMISSION_ASSET_POST,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_MANAGE,
    ]],

    AccessMatrix::ROLE_CASHIER => [AccessMatrix::ROLE_CASHIER, [
        AccessMatrix::PERMISSION_JOURNAL_VIEW,
        AccessMatrix::PERMISSION_JOURNAL_CREATE,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_INVOICE_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_RECORD,
        AccessMatrix::PERMISSION_BANK_VIEW,
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_ASSET_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
    ]],

    AccessMatrix::ROLE_INVENTORY_MANAGER => [AccessMatrix::ROLE_INVENTORY_MANAGER, [
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_STOCK_MANAGE,
        AccessMatrix::PERMISSION_STOCK_POST,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
    ]],

    AccessMatrix::ROLE_AUDITOR => [AccessMatrix::ROLE_AUDITOR, [
        AccessMatrix::PERMISSION_JOURNAL_VIEW,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_INVOICE_VIEW,
        AccessMatrix::PERMISSION_BILL_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_VIEW,
        AccessMatrix::PERMISSION_BANK_VIEW,
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_AUDIT_VIEW,
        AccessMatrix::PERMISSION_BUDGET_VIEW,
        AccessMatrix::PERMISSION_ASSET_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
    ]],

    AccessMatrix::ROLE_READ_ONLY => [AccessMatrix::ROLE_READ_ONLY, [
        AccessMatrix::PERMISSION_JOURNAL_VIEW,
        AccessMatrix::PERMISSION_REPORT_VIEW,
        AccessMatrix::PERMISSION_PARTY_VIEW,
        AccessMatrix::PERMISSION_INVOICE_VIEW,
        AccessMatrix::PERMISSION_BILL_VIEW,
        AccessMatrix::PERMISSION_PAYMENT_VIEW,
        AccessMatrix::PERMISSION_BANK_VIEW,
        AccessMatrix::PERMISSION_STOCK_VIEW,
        AccessMatrix::PERMISSION_ASSET_VIEW,
        AccessMatrix::PERMISSION_ATTACHMENT_VIEW,
    ]],
]);

it('lists the seven roles and what each grants, to the Owner alone', function () {
    Sanctum::actingAs(nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.name', AccessMatrix::ROLE_OWNER)
        ->assertJsonPath('data.0.is_default', false)
        // Least privilege is the default a fresh invite starts on.
        ->assertJsonPath('data.5.name', AccessMatrix::ROLE_AUDITOR)
        ->assertJsonPath('data.5.is_default', true);
});

/*
|--------------------------------------------------------------------------
| Administering members
|--------------------------------------------------------------------------
*/

it('refuses member administration to every role except the Owner', function (string $role) {
    $member = nxAdminMember($this->companyId, $role);
    $colleague = nxAdminMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    Sanctum::actingAs($member);

    $this->getJson('/api/v1/users')->assertForbidden();
    $this->postJson('/api/v1/users', [])->assertForbidden();
    $this->getJson('/api/v1/users/'.$colleague->ulid)->assertForbidden();
    $this->postJson('/api/v1/users/'.$colleague->ulid.'/deactivate')->assertForbidden();
    // The rights matrix is administration too, so it is refused with them.
    $this->getJson('/api/v1/roles')->assertForbidden();
})->with([
    AccessMatrix::ROLE_ACCOUNTANT,
    AccessMatrix::ROLE_FINANCE_OFFICER,
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_INVENTORY_MANAGER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_READ_ONLY,
]);

it('invites a member onto the least privileged role and records the invitation', function () {
    Sanctum::actingAs(nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/users', [
        'name' => 'Amina Hassan',
        'email' => 'amina@nexas.co.tz',
        'password' => 'secret-key-2026',
        'job_title' => 'Storekeeper',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'amina@nexas.co.tz')
        ->assertJsonPath('data.status', 'active')
        // Least privilege: an invite that names no role starts on the role that
        // lets the member read the books and nothing else.
        ->assertJsonPath('data.roles', [AccessMatrix::defaultRole()]);

    $this->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'user.invited',
    ]);
});

it('refuses to invite somebody who is already a member', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);
    $member = nxAdminMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/users', [
        'name' => $member->name,
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AccessStateException');
});

it('assigns and removes a role, recording both moves', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);
    $member = nxAdminMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/users/'.$member->ulid.'/roles', [
        'role' => AccessMatrix::ROLE_CASHIER,
    ])
        ->assertOk()
        // Roles are additive: the Cashier grant is added, the Auditor grant is
        // not taken away.
        ->assertJsonPath('data.roles', [AccessMatrix::ROLE_AUDITOR, AccessMatrix::ROLE_CASHIER]);

    $this->deleteJson('/api/v1/users/'.$member->ulid.'/roles/'.rawurlencode(AccessMatrix::ROLE_CASHIER))
        ->assertOk()
        ->assertJsonPath('data.roles', [AccessMatrix::ROLE_AUDITOR]);

    $this->assertDatabaseHas('audit_logs', ['action' => 'user.role.assigned']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.role.removed']);
});

it('refuses to remove a role the member does not hold', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);
    $member = nxAdminMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    Sanctum::actingAs($owner);

    $this->deleteJson('/api/v1/users/'.$member->ulid.'/roles/'.rawurlencode(AccessMatrix::ROLE_CASHIER))
        ->assertStatus(422)
        ->assertJsonPath('error', 'AccessException');
});

it('refuses to strip the last Owner of the role that keeps the company administrable', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);

    $this->deleteJson('/api/v1/users/'.$owner->ulid.'/roles/'.rawurlencode(AccessMatrix::ROLE_OWNER))
        ->assertStatus(409)
        ->assertJsonPath('error', 'AccessStateException');

    // The refusal is complete: the Owner still holds the role.
    setPermissionsTeamId($this->companyId);
    expect($owner->fresh()->hasRole(AccessMatrix::ROLE_OWNER))->toBeTrue();
    setPermissionsTeamId(null);
});

it('allows the last Owner to be replaced once a second Owner exists', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);
    $second = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);

    // Two Owners exist, so removing one of them leaves the company manageable
    // and the guard has nothing to refuse.
    $this->deleteJson('/api/v1/users/'.$second->ulid.'/roles/'.rawurlencode(AccessMatrix::ROLE_OWNER))
        ->assertOk()
        ->assertJsonPath('data.roles', []);
});

it('refuses a member the right to deactivate their own account', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/users/'.$owner->ulid.'/deactivate')
        ->assertStatus(422)
        ->assertJsonPath('error', 'AccessException');

    expect(DB::table('users')->where('id', $owner->getKey())->value('status'))->toBe('active');
});

it('deactivates a member, revokes their tokens and records it', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);
    $member = nxAdminMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    $member->createToken('pest-device');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $member->getKey())->count())->toBe(1);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/users/'.$member->ulid.'/deactivate')
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    // A token that still authenticates is access, so switching the account off
    // signs every device out at once.
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $member->getKey())->count())->toBe(0);

    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'user.deactivated',
    ]);

    // Deactivation is reversible, and reactivating puts the member back without
    // restoring the tokens that were revoked.
    $this->postJson('/api/v1/users/'.$member->ulid.'/activate')
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('cannot reach a member of another company', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);

    $outsider = User::factory()->create();
    $outsider->companies()->attach(nxAdminForeignCompanyId(), ['is_default' => true, 'status' => 'active']);

    Sanctum::actingAs($owner);

    // The member is resolved scoped to the caller's own company in the same
    // query that finds them, so naming somebody else's member is a 404 and not
    // an existence oracle.
    $this->getJson('/api/v1/users/'.$outsider->ulid)->assertNotFound();
    $this->postJson('/api/v1/users/'.$outsider->ulid.'/deactivate')->assertNotFound();
    $this->postJson('/api/v1/users/'.$outsider->ulid.'/roles', [
        'role' => AccessMatrix::ROLE_CASHIER,
    ])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Reading the audit chain
|--------------------------------------------------------------------------
*/

it('shows the audit chain to the Owner and the Auditor, and to nobody else', function (string $role, int $expected) {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/users', [
        'name' => 'Amina Hassan',
        'email' => 'amina@nexas.co.tz',
        'password' => 'secret-key-2026',
    ])->assertCreated();

    Sanctum::actingAs(nxAdminMember($this->companyId, $role));

    $this->getJson('/api/v1/audit-logs')->assertStatus($expected);

    if ($expected === 200) {
        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'user.invited')
            ->assertJsonPath('data.0.subject_type', 'User');
    }
})->with([
    // `audit.view` is held by the Owner and the Auditor alone: a member trusted
    // to read the books is not automatically trusted to read the trail.
    'Auditor reads the trail' => [AccessMatrix::ROLE_AUDITOR, 200],
    'Read-only is refused' => [AccessMatrix::ROLE_READ_ONLY, 403],
    'Accountant is refused' => [AccessMatrix::ROLE_ACCOUNTANT, 403],
    'Finance Officer is refused' => [AccessMatrix::ROLE_FINANCE_OFFICER, 403],
]);

it('scopes the audit chain to the caller company', function () {
    $owner = nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER);

    DB::table('audit_logs')->insert([
        'ulid' => (string) Str::ulid(),
        'company_id' => nxAdminForeignCompanyId(),
        'action' => 'user.invited',
        'auditable_type' => User::class,
        'auditable_id' => 1,
        'ip_address' => '127.0.0.1',
        'created_at' => now(),
    ]);

    Sanctum::actingAs($owner);

    $this->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

/*
|--------------------------------------------------------------------------
| Segregation of duties
|--------------------------------------------------------------------------
*/

it('refuses a bill approval to the member who entered the bill, whatever their rights', function () {
    // The Owner holds bill.approve, so a refusal here can only be the
    // self-approval rule and not a missing permission.
    Sanctum::actingAs(nxAdminMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $supplierId = nxAdminSupplier($this->companyId, $this->payableAccountId);

    $draft = $this->postJson('/api/v1/bills', nxAdminBillBody($supplierId, $this->expenseAccountId))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/submit')->assertOk();

    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/approve')
        ->assertStatus(409)
        ->assertJsonPath('error', 'BillStateException');

    expect(DB::table('bills')->where('id', $draft['id'])->value('status'))->toBe('pending_approval');
});

it('lets a second authorised member clear the bill the first one entered', function () {
    $accountant = nxAdminMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    $approver = nxAdminMember($this->companyId, AccessMatrix::ROLE_FINANCE_OFFICER);

    Sanctum::actingAs($accountant);

    $supplierId = nxAdminSupplier($this->companyId, $this->payableAccountId);

    $draft = $this->postJson('/api/v1/bills', nxAdminBillBody($supplierId, $this->expenseAccountId))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/submit')->assertOk();

    Sanctum::actingAs($approver);

    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approval_status', 'approved')
        ->assertJsonPath('data.approved_by_user_id', $approver->getKey());
});

it('refuses the finance officer the right to enter or edit a bill', function () {
    Sanctum::actingAs(nxAdminMember($this->companyId, AccessMatrix::ROLE_FINANCE_OFFICER));

    // Approve, don't enter: the role is the second pair of hands, so writing
    // the bill it will clear is exactly what it must not be able to do. Reading
    // it is what the second pair of hands needs, so that still answers.
    $this->postJson('/api/v1/bills', [])->assertForbidden();
    $this->getJson('/api/v1/bills')->assertOk();
});

it('keeps the inventory manager out of the financial books', function () {
    Sanctum::actingAs(nxAdminMember($this->companyId, AccessMatrix::ROLE_INVENTORY_MANAGER));

    // The stock books are this role's job...
    $this->getJson('/api/v1/stock/movements')->assertOk();
    $this->getJson('/api/v1/products')->assertOk();
    $this->getJson('/api/v1/suppliers')->assertOk();

    // ...and the money books, the payments and the members are not.
    $this->postJson('/api/v1/journals', nxAdminJournalBody($this->cashAccountId, $this->revenueAccountId))
        ->assertForbidden();
    $this->getJson('/api/v1/journals')->assertForbidden();
    $this->postJson('/api/v1/payments', [])->assertForbidden();
    $this->getJson('/api/v1/users')->assertForbidden();
});
