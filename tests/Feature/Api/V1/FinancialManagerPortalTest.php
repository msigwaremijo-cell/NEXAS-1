<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Approvals\Actions\DecideApprovalRequest;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Models\ApprovalRequest;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The Financial Manager portal, and the queue behind it, over the real schema:
// the audit chain is written by MySQL triggers and the queue's "one document,
// one decision" rule is a unique key, so neither answers on a fake.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxFmMember(int $companyId, string $role, string $password = 'secret-key-2026'): User
{
    $user = User::factory()->create(['password' => $password]);

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxFmUlid(string $table, int $id): string
{
    return (string) DB::table($table)->where('id', $id)->value('ulid');
}

/**
 * A journal for the queue to hold, raised rather than approved by any member.
 */
function nxFmJournal(int $companyId, string $number): string
{
    return nxFmUlid(
        'journals',
        test()->balancedJournal($number),
    );
}

function nxFmPeriod(int $companyId, int $fiscalYearId, int $periodNo, string $name, string $startsOn, string $endsOn): int
{
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
 * A request waiting in the queue, raised by the Accountant so that whoever the
 * test then acts as is a second pair of hands rather than its author.
 *
 * @return array{ulid: string, journal: string}
 */
function nxFmPending(int $companyId, string $number = 'JV-000001'): array
{
    $journal = nxFmJournal($companyId, $number);

    Sanctum::actingAs(nxFmMember($companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $ulid = test()->postJson('/api/v1/approval-requests', [
        'document_type' => 'journal',
        'document_ulid' => $journal,
    ])->assertCreated()->json('data.ulid');

    return ['ulid' => (string) $ulid, 'journal' => $journal];
}

/*
|--------------------------------------------------------------------------
| The portal door
|--------------------------------------------------------------------------
*/

it('signs a Financial Manager in through the portal and hands back a token', function () {
    $member = nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    test()->postJson('/api/v1/auth/financial-manager/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('company_id', $this->companyId)
        ->assertJsonPath('user.roles', [AccessMatrix::ROLE_FINANCIAL_MANAGER])
        ->assertJsonPath('user.permissions', fn (array $permissions): bool => in_array(
            AccessMatrix::PERMISSION_APPROVAL_DECIDE,
            $permissions,
            true,
        ));

    // A token that can actually be used, rather than only a string in a body.
    $token = test()->postJson('/api/v1/auth/financial-manager/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])->json('token');

    expect($token)->toBeString()->not->toBe('');

    test()->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/approval-requests')
        ->assertOk();
});

it('refuses the portal to a member who does not hold the Financial Manager role', function (string $role) {
    $member = nxFmMember($this->companyId, $role);

    test()->postJson('/api/v1/auth/financial-manager/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email')
        ->assertJsonMissingPath('token');
})->with([
    AccessMatrix::ROLE_OWNER,
    AccessMatrix::ROLE_ACCOUNTANT,
    AccessMatrix::ROLE_FINANCE_OFFICER,
    AccessMatrix::ROLE_AUDITOR,
]);

it('refuses the portal to a wrong password', function () {
    $member = nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    test()->postJson('/api/v1/auth/financial-manager/login', [
        'email' => $member->email,
        'password' => 'not-the-password',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email')
        ->assertJsonMissingPath('token');
});

/*
|--------------------------------------------------------------------------
| Who may decide
|--------------------------------------------------------------------------
*/

it('lets the Financial Manager decide and refuses every other role', function (string $role) {
    $pending = nxFmPending($this->companyId);

    Sanctum::actingAs(nxFmMember($this->companyId, $role));

    $response = test()->postJson(
        "/api/v1/approval-requests/{$pending['ulid']}/approve",
        [],
        ['Idempotency-Key' => 'decide-'.$role],
    );

    if ($role === AccessMatrix::ROLE_FINANCIAL_MANAGER) {
        $response->assertOk()->assertJsonPath('data.status', 'approved');

        return;
    }

    // A refusal for a role that holds every other right is the point: the
    // Owner is refused the decision, and so is the Accountant who entered it.
    $response->assertForbidden();

    expect(DB::table('approval_requests')->where('ulid', $pending['ulid'])->value('status'))->toBe('pending')
        ->and(DB::table('audit_logs')->where('action', 'approval.approved')->count())->toBe(0);
})->with([
    AccessMatrix::ROLE_FINANCIAL_MANAGER,
    AccessMatrix::ROLE_OWNER,
    AccessMatrix::ROLE_ACCOUNTANT,
    AccessMatrix::ROLE_FINANCE_OFFICER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_INVENTORY_MANAGER,
    AccessMatrix::ROLE_READ_ONLY,
]);

it('shows the queue to a role that may read it and refuses one that may not', function () {
    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    test()->getJson('/api/v1/approval-requests')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);

    // A Cashier raises receipts for a decision but does not read the whole
    // company's line.
    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    test()->getJson('/api/v1/approval-requests')->assertForbidden();
});

it('refuses the Financial Manager the member list and the company settings', function () {
    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    // The whole of "cannot manage users or system settings".
    test()->getJson('/api/v1/users')->assertForbidden();
    test()->getJson('/api/v1/company/inventory-settings')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Nobody approves their own entry
|--------------------------------------------------------------------------
*/

it('refuses a member deciding a request they raised themselves', function () {
    $manager = nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    $approval = test()->postJson('/api/v1/approval-requests', [
        'document_type' => 'journal',
        'document_ulid' => nxFmJournal($this->companyId, 'JV-000001'),
    ])->assertCreated()->json('data.ulid');

    test()->postJson("/api/v1/approval-requests/{$approval}/approve", [], ['Idempotency-Key' => 'self-approve'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'ApprovalStateException');

    // The rule guards every decision, not only the accepting one: refusing a
    // document you raised yourself is the same second signature.
    $second = test()->postJson('/api/v1/approval-requests', [
        'document_type' => 'journal',
        'document_ulid' => nxFmJournal($this->companyId, 'JV-000002'),
    ])->assertCreated()->json('data.ulid');

    test()->postJson("/api/v1/approval-requests/{$second}/reject", [
        'reason' => 'The supporting invoice is missing from the file.',
    ], ['Idempotency-Key' => 'self-reject'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'ApprovalStateException');

    expect(DB::table('approval_requests')->where('status', 'pending')->count())->toBe(2)
        ->and(DB::table('audit_logs')->whereIn('action', ['approval.approved', 'approval.rejected'])->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A rejection says why
|--------------------------------------------------------------------------
*/

it('refuses a rejection that carries no reason', function () {
    $pending = nxFmPending($this->companyId);

    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $url = "/api/v1/approval-requests/{$pending['ulid']}/reject";

    test()->postJson($url, [], ['Idempotency-Key' => 'reject-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    // And a word is not a reason: the member who raised the document is given
    // this and nothing else to act on.
    test()->postJson($url, ['reason' => 'no'], ['Idempotency-Key' => 'reject-2'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect(DB::table('approval_requests')->where('ulid', $pending['ulid'])->value('status'))->toBe('pending')
        ->and(DB::table('audit_logs')->where('action', 'approval.rejected')->count())->toBe(0);
});

it('refuses a reason of only whitespace even when the action is reached directly', function () {
    $pending = nxFmPending($this->companyId);

    $manager = nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    $request = ApprovalRequest::query()->where('ulid', $pending['ulid'])->sole();

    // The HTTP layer already requires a reason, so this is the second line of
    // defence: the rule holds for any code path that reaches the action.
    expect(fn () => app(DecideApprovalRequest::class)->reject($request, "   \n  ", $manager->getKey(), 'blank'))
        ->toThrow(ApprovalsException::class, 'must carry a reason');
});

/*
|--------------------------------------------------------------------------
| Every decision is idempotent and lands in the trail
|--------------------------------------------------------------------------
*/

it('requires an idempotency key on every decision', function () {
    $pending = nxFmPending($this->companyId);

    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    test()->postJson("/api/v1/approval-requests/{$pending['ulid']}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error', 'ApprovalsException');

    expect(DB::table('approval_requests')->where('ulid', $pending['ulid'])->value('status'))->toBe('pending');
});

it('replays a decision instead of deciding the document twice', function () {
    $pending = nxFmPending($this->companyId);

    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $url = "/api/v1/approval-requests/{$pending['ulid']}/approve";

    $first = test()->postJson($url, [], ['Idempotency-Key' => 'once'])->assertOk()->json('data');
    $second = test()->postJson($url, [], ['Idempotency-Key' => 'once'])->assertOk()->json('data');

    expect($second['status'])->toBe('approved')
        ->and($second['row_version'])->toBe($first['row_version'])
        ->and($second['decided_by_user_id'])->toBe($first['decided_by_user_id'])
        // One decision, one link in the chain: the retry replayed the first
        // answer rather than writing a second.
        ->and(DB::table('audit_logs')->where('action', 'approval.approved')->count())->toBe(1);
});

it('records the approval and the rejection, with their reasons, in the audit chain', function () {
    $approved = nxFmPending($this->companyId, 'JV-000001');
    $rejected = nxFmPending($this->companyId, 'JV-000002');

    $manager = nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    Sanctum::actingAs($manager);

    test()->postJson("/api/v1/approval-requests/{$approved['ulid']}/approve", [], ['Idempotency-Key' => 'a'])
        ->assertOk();

    $reason = 'The expense account is wrong and the bill must be re-entered.';

    test()->postJson("/api/v1/approval-requests/{$rejected['ulid']}/reject", ['reason' => $reason], ['Idempotency-Key' => 'r'])
        ->assertOk()
        ->assertJsonPath('data.decision_reason', $reason);

    $approvedEntry = DB::table('audit_logs')->where('action', 'approval.approved')->sole();
    $rejectedEntry = DB::table('audit_logs')->where('action', 'approval.rejected')->sole();

    expect((int) $approvedEntry->user_id)->toBe($manager->getKey())
        ->and($approvedEntry->auditable_type)->toBe('App\Models\ApprovalRequest')
        ->and(json_decode((string) $approvedEntry->before, true))->toBe(['status' => 'pending'])
        ->and(json_decode((string) $approvedEntry->after, true)['status'])->toBe('approved');

    expect((int) $rejectedEntry->user_id)->toBe($manager->getKey())
        ->and(json_decode((string) $rejectedEntry->after, true)['decision_reason'])->toBe($reason)
        ->and(json_decode((string) $rejectedEntry->after, true)['status'])->toBe('rejected');
});

/*
|--------------------------------------------------------------------------
| The calendar the Financial Manager keeps
|--------------------------------------------------------------------------
*/

it('lets the Financial Manager close the year and reopen a period, and refuses a reopen without a reason', function () {
    // A final period for the closing entry to be dated into, and a result for
    // it to carry.
    nxFmPeriod($this->companyId, $this->fiscalYearId, 12, 'December 2026', '2026-12-01', '2026-12-31');
    $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');
    $this->postJournal($this->balancedJournal('JV-000100', '500.0000'));

    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    test()->postJson('/api/v1/fiscal-years/'.nxFmUlid('fiscal_years', $this->fiscalYearId).'/close')
        ->assertOk();

    expect(DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->value('status'))->toBe('closed');

    $url = '/api/v1/fiscal-periods/'.nxFmUlid('fiscal_periods', $this->fiscalPeriodId).'/reopen';

    // A reopen is what the trail says a reported period was opened again for,
    // so the reason is required rather than optional — of the Financial
    // Manager as much as of anyone else.
    test()->postJson($url)->assertStatus(422)->assertJsonValidationErrors('reason');

    expect(DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->value('status'))->toBe('closed');

    test()->postJson($url, ['reason' => 'March bank charges were posted to the wrong account.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');

    expect(DB::table('audit_logs')->where('action', 'fiscal_period.reopened')->count())->toBe(1);
});

it('refuses the reopen to every role but the Owner and the Financial Manager', function (string $role) {
    nxFmPeriod($this->companyId, $this->fiscalYearId, 12, 'December 2026', '2026-12-01', '2026-12-31');
    $this->makeAccount('3200', 'Retained Earnings', 'equity', 'credit');
    $this->postJournal($this->balancedJournal('JV-000100', '500.0000'));

    Sanctum::actingAs(nxFmMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    test()->postJson('/api/v1/fiscal-years/'.nxFmUlid('fiscal_years', $this->fiscalYearId).'/close')
        ->assertOk();

    Sanctum::actingAs(nxFmMember($this->companyId, $role));

    test()->postJson('/api/v1/fiscal-periods/'.nxFmUlid('fiscal_periods', $this->fiscalPeriodId).'/reopen', [
        'reason' => 'A correction is needed in the March period.',
    ])->assertForbidden();

    expect(DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->value('status'))->toBe('closed');
})->with([
    AccessMatrix::ROLE_ACCOUNTANT,
    AccessMatrix::ROLE_FINANCE_OFFICER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_INVENTORY_MANAGER,
    AccessMatrix::ROLE_READ_ONLY,
]);
