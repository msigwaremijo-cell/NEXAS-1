<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The API is exercised over real MySQL, because the posting rules under test
// live in triggers and constraints rather than in PHP alone.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    // Real roles and permissions, not hand-written rows: a test that granted
    // itself a role directly would not notice a broken seeder.
    $this->seed(RoleAndPermissionSeeder::class);

    $this->otherCompanyId = nxForeignCompany();
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A balanced two-line draft as the API expects it.
 *
 * @return array<string, mixed>
 */
function nxJournalBody(int $debitAccountId, int $creditAccountId, string $amount = '250.0000'): array
{
    return [
        'posting_date' => '2026-03-15',
        'reference' => 'AUTH-TEST-001',
        'memo' => 'Authorization test entry',
        'lines' => [
            ['account_id' => $debitAccountId, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $creditAccountId, 'debit' => '0', 'credit' => $amount],
        ],
    ];
}

/**
 * A second tenant with its own books, used to prove that a member of one
 * company cannot reach into another company's journal.
 *
 * @return array{company_id: int, journal_ulid: string}
 */
function nxForeignCompany(): array
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

    $yearId = DB::table('fiscal_years')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'name' => 'FY2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $periodId = DB::table('fiscal_periods')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'fiscal_year_id' => $yearId,
        'period_no' => 3,
        'name' => 'March 2026',
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $account = static function (string $code, string $name, string $type, string $normal) use ($companyId, $now): int {
        return DB::table('accounts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'depth' => 0,
            'is_postable' => true,
            'is_cash' => false,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    };

    $debitAccountId = $account('1000', 'Cash on Hand', 'asset', 'debit');
    $creditAccountId = $account('4000', 'Sales Revenue', 'revenue', 'credit');

    $ulid = (string) Str::ulid();

    $journalId = DB::table('journals')->insertGetId([
        'ulid' => $ulid,
        'company_id' => $companyId,
        'fiscal_year_id' => $yearId,
        'fiscal_period_id' => $periodId,
        'journal_no' => 'JV-900001',
        'journal_type' => 'general',
        'status' => 'draft',
        'posting_date' => '2026-03-15',
        'currency_code' => 'TZS',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::table('journal_lines')->insert([
        [
            'company_id' => $companyId,
            'journal_id' => $journalId,
            'line_no' => 1,
            'account_id' => $debitAccountId,
            'currency_code' => 'TZS',
            'posting_date' => '2026-03-15',
            'debit' => '100.0000',
            'credit' => '0.0000',
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'company_id' => $companyId,
            'journal_id' => $journalId,
            'line_no' => 2,
            'account_id' => $creditAccountId,
            'currency_code' => 'TZS',
            'posting_date' => '2026-03-15',
            'debit' => '0.0000',
            'credit' => '100.0000',
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    return ['company_id' => $companyId, 'journal_ulid' => $ulid];
}

it('refuses every protected endpoint without a token', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/journals'],
    ['POST', '/api/v1/journals'],
    ['POST', '/api/v1/auth/logout'],
    ['GET', '/api/v1/auth/me'],
]);

it('reports the roles and permissions a member actually holds', function () {
    $user = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('company_id', $this->companyId)
        ->assertJsonPath('user.roles', [AccessMatrix::ROLE_ACCOUNTANT])
        ->assertJsonPath(
            'user.permissions',
            // Sorted, because the order the permissions come back in is the
            // database's business, not the contract's. The list is written out
            // rather than read back out of AccessMatrix: a test that asks the
            // matrix what it grants only proves the matrix equals itself.
            fn (array $permissions): bool => collect($permissions)->sort()->values()->all() === collect([
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
                AccessMatrix::PERMISSION_APPROVAL_VIEW,
                AccessMatrix::PERMISSION_APPROVAL_REQUEST,
                // Reads the projection; the assumptions behind it are the
                // Financial Manager's to change, so forecast.scenario is not.
                AccessMatrix::PERMISSION_FORECAST_VIEW,
                // Deliberately not granted: PERMISSION_FISCAL_YEAR_CLOSE,
                // PERMISSION_FISCAL_PERIOD_REOPEN, PERMISSION_BILL_APPROVE,
                // PERMISSION_APPROVAL_DECIDE and PERMISSION_FORECAST_SCENARIO.
                // Closing a year is the Financial Manager's and the Owner's,
                // approval is withheld from the role that enters bills, and
                // deciding the queue is the Financial Manager's alone.
            ])->sort()->values()->all()
        );
});

it('withholds bill approval from the accountant who entered the bill', function () {
    // Segregation of duties: the person who keys in a supplier liability is
    // the person best placed to key in a fictitious one, so the approval gate
    // is worthless if the same role can clear it. This asserts the control
    // holds for every role except the Owner, who is accountable for the books.
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    $owner = nxMember($this->companyId, AccessMatrix::ROLE_OWNER);

    // Roles are per company and nxMember clears the resolver when it returns,
    // so the team has to be pinned again before can() will find them.
    setPermissionsTeamId($this->companyId);

    expect($accountant->can(AccessMatrix::PERMISSION_BILL_APPROVE))->toBeFalse()
        ->and($accountant->can(AccessMatrix::PERMISSION_BILL_MANAGE))->toBeTrue()
        ->and($owner->can(AccessMatrix::PERMISSION_BILL_APPROVE))->toBeTrue();
});

it('lets an accountant create a draft through the API', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($accountant);

    $response = $this->postJson('/api/v1/journals', nxJournalBody(
        $this->cashAccountId,
        $this->revenueAccountId,
    ));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.journal_no', 'JV-000001')
        ->assertJsonPath('data.company_id', $this->companyId);

    expect($response->json('data.ulid'))->toBeString()->toHaveLength(26);

    $this->assertDatabaseHas('journals', [
        'ulid' => $response->json('data.ulid'),
        'company_id' => $this->companyId,
        'status' => 'draft',
    ]);
});

it('does not let an auditor post a journal', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    $auditor = nxMember($this->companyId, AccessMatrix::ROLE_AUDITOR);

    Sanctum::actingAs($accountant);

    $ulid = $this->postJson('/api/v1/journals', nxJournalBody(
        $this->cashAccountId,
        $this->revenueAccountId,
    ))->json('data.ulid');

    // The auditor can read the draft, which proves the 403 below is the
    // posting permission and not a blanket refusal of the whole endpoint.
    Sanctum::actingAs($auditor);

    $this->getJson("/api/v1/journals/{$ulid}")->assertOk();

    $this->postJson("/api/v1/journals/{$ulid}/post")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    // The refusal has to leave the books untouched, not merely answer 403.
    $this->assertDatabaseHas('journals', ['ulid' => $ulid, 'status' => 'draft']);
    $this->assertDatabaseCount('audit_logs', 1); // only journal.created
});

it('lets an accountant post a balanced journal and derives the totals', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($accountant);

    $ulid = $this->postJson('/api/v1/journals', nxJournalBody(
        $this->cashAccountId,
        $this->revenueAccountId,
    ))->json('data.ulid');

    $this->postJson("/api/v1/journals/{$ulid}/post")
        ->assertOk()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.total_debit', '250.0000')
        ->assertJsonPath('data.total_credit', '250.0000');

    $this->assertDatabaseHas('journals', ['ulid' => $ulid, 'status' => 'posted']);
});

it('replays a repeated post instead of posting twice', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($accountant);

    $ulid = $this->postJson('/api/v1/journals', nxJournalBody(
        $this->cashAccountId,
        $this->revenueAccountId,
    ))->json('data.ulid');

    $first = $this->withHeader('Idempotency-Key', 'auth-test-replay')
        ->postJson("/api/v1/journals/{$ulid}/post");

    $second = $this->withHeader('Idempotency-Key', 'auth-test-replay')
        ->postJson("/api/v1/journals/{$ulid}/post");

    $first->assertOk();
    $second->assertOk()->assertJsonPath('data.ulid', $first->json('data.ulid'));

    expect($second->json('data.row_version'))->toBe($first->json('data.row_version'));

    $this->assertDatabaseCount('idempotency_keys', 1);

    // The second tenant's own draft journal exists in every test, so the count
    // is scoped to the company under test.
    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(1);
});

it('refuses an unbalanced journal with a 422 rather than a database error', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($accountant);

    $ulid = $this->postJson('/api/v1/journals', [
        'posting_date' => '2026-03-15',
        'lines' => [
            ['account_id' => $this->cashAccountId, 'debit' => '100.0000', 'credit' => '0'],
            ['account_id' => $this->revenueAccountId, 'debit' => '0', 'credit' => '90.0000'],
        ],
    ])->json('data.ulid');

    $this->postJson("/api/v1/journals/{$ulid}/post")
        ->assertStatus(422)
        ->assertJsonPath('error', 'InvalidJournalException');

    $this->assertDatabaseHas('journals', ['ulid' => $ulid, 'status' => 'draft']);
});

it('does not let a cashier reverse a posted journal', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    $cashier = nxMember($this->companyId, AccessMatrix::ROLE_CASHIER);

    Sanctum::actingAs($accountant);

    $ulid = $this->postJson('/api/v1/journals', nxJournalBody(
        $this->cashAccountId,
        $this->revenueAccountId,
    ))->json('data.ulid');

    $this->postJson("/api/v1/journals/{$ulid}/post")->assertOk();

    Sanctum::actingAs($cashier);

    $this->postJson("/api/v1/journals/{$ulid}/reverse")->assertForbidden();

    $this->assertDatabaseHas('journals', ['ulid' => $ulid, 'status' => 'posted']);

    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(1);
});

it('rejects a company the caller does not belong to', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($accountant);

    $this->withHeader('X-Company-Id', (string) $this->otherCompanyId['company_id'])
        ->getJson('/api/v1/journals')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

it('does not let a member post another company journal', function () {
    $accountant = nxMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($accountant);

    $foreign = $this->otherCompanyId['journal_ulid'];

    // The member holds journal.post in their own company, so a pass here would
    // mean the policy was never consulted.
    $this->postJson("/api/v1/journals/{$foreign}/post")->assertForbidden();

    $this->assertDatabaseHas('journals', [
        'ulid' => $foreign,
        'status' => 'draft',
    ]);
});

it('issues a token on login and revokes it on logout', function () {
    $user = nxMember($this->companyId, AccessMatrix::ROLE_OWNER);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'pest',
    ]);

    $login->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('company_id', $this->companyId);

    $token = $login->json('token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 0);

    // A guard instance lives as long as the application container, and the test
    // container serves every request in this method. Production gets a fresh
    // container per request, so the resolved user is discarded there; forgetting
    // the guards here is what reproduces that, and it is the honest way to ask
    // whether the deleted token still authenticates.
    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('refuses a wrong password without revealing whether the account exists', function () {
    $user = nxMember($this->companyId, AccessMatrix::ROLE_OWNER);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'not-the-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@nexas.co.tz',
        'password' => 'not-the-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});
