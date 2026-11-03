<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The approvals screen over the real schema. The queue's figures are sums of
// the documents' own lines and its idempotency is a unique key, so neither
// answers on a fake.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxApMember(int $companyId, string $role): User
{
    $user = User::factory()->create(['password' => 'secret-key-2026']);

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxApUlid(string $table, int $id): string
{
    return (string) DB::table($table)->where('id', $id)->value('ulid');
}

/**
 * Raises a document into the queue as the Accountant, so that whoever the test
 * then acts as is a second pair of hands rather than its author. Returns the
 * request's own ULID — the handle the screen works with.
 */
function nxApRaise(int $companyId, string $type, string $documentUlid): string
{
    Sanctum::actingAs(nxApMember($companyId, AccessMatrix::ROLE_ACCOUNTANT));

    return (string) test()->postJson('/api/v1/approval-requests', [
        'document_type' => $type,
        'document_ulid' => $documentUlid,
    ])->assertCreated()->json('data.ulid');
}

/**
 * A journal of the given value, waiting in the queue.
 */
function nxApQueue(int $companyId, string $number, string $amount = '100.0000'): string
{
    return nxApRaise($companyId, 'journal', nxApUlid('journals', test()->balancedJournal($number, $amount)));
}

/*
|--------------------------------------------------------------------------
| The header is about the queue, the line is about the page
|--------------------------------------------------------------------------
*/

it('states the queue over the whole company while the line is paged', function () {
    nxApQueue($this->companyId, 'JV-000001', '10.0000');
    nxApQueue($this->companyId, 'JV-000002', '20.0000');
    nxApQueue($this->companyId, 'JV-000003', '30.0000');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $response = test()->getJson('/api/v1/approval-requests?page=1&per_page=1')->assertOk();

    // One row on the page, three in the header: a header that moved as a reader
    // turned a page would be a header about the page.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(3)
        ->and($response->json('meta.summary.pending_count'))->toBe(3)
        ->and($response->json('meta.summary.pending_value'))->toBe('60.0000');
});

/*
|--------------------------------------------------------------------------
| The filters are answered by the server
|--------------------------------------------------------------------------
*/

it('narrows the line by status, by document kind and by amount', function () {
    $first = nxApQueue($this->companyId, 'JV-000001', '100.0000');
    nxApQueue($this->companyId, 'JV-000002', '5000.0000');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    test()->postJson("/api/v1/approval-requests/{$first}/approve", [], ['Idempotency-Key' => 'ap-1'])->assertOk();

    expect(test()->getJson('/api/v1/approval-requests?status=pending')->json('meta.total'))->toBe(1)
        ->and(test()->getJson('/api/v1/approval-requests?status=approved')->json('meta.total'))->toBe(1)
        ->and(test()->getJson('/api/v1/approval-requests?document_type=journal')->json('meta.total'))->toBe(2)
        // The amount is the document's own total, summed from its lines while
        // the journal is still a draft.
        ->and(test()->getJson('/api/v1/approval-requests?amount_min=1000')->json('meta.total'))->toBe(1)
        ->and(test()->getJson('/api/v1/approval-requests?amount_max=1000')->json('meta.total'))->toBe(1);
});

it('narrows the line by how long a document has waited', function () {
    $old = nxApQueue($this->companyId, 'JV-000001');
    nxApQueue($this->companyId, 'JV-000002');

    // Backdated rather than waited for: the age is measured on when the request
    // was raised, so moving that is what makes the wait real.
    DB::table('approval_requests')->where('ulid', $old)->update(['created_at' => now()->subDays(30)]);

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $stale = test()->getJson('/api/v1/approval-requests?age_min=7')->assertOk();
    expect($stale->json('meta.total'))->toBe(1)
        ->and($stale->json('data.0.ulid'))->toBe($old)
        ->and($stale->json('data.0.age_days'))->toBeGreaterThanOrEqual(29);

    $fresh = test()->getJson('/api/v1/approval-requests?age_max=7')->assertOk();
    expect($fresh->json('meta.total'))->toBe(1)
        ->and($fresh->json('data.0.ulid'))->not->toBe($old);
});

it('searches the line by the document reference', function () {
    nxApQueue($this->companyId, 'JV-000042');
    nxApQueue($this->companyId, 'JV-000099');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $found = test()->getJson('/api/v1/approval-requests?search=000042')->assertOk();

    expect($found->json('meta.total'))->toBe(1)
        ->and($found->json('data.0.document_reference'))->toBe('JV-000042');
});

it('orders the line by the sorting the reader asked for', function () {
    nxApQueue($this->companyId, 'JV-000001', '100.0000');
    nxApQueue($this->companyId, 'JV-000002', '300.0000');
    nxApQueue($this->companyId, 'JV-000003', '200.0000');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $amounts = fn (string $query): array => array_map(
        fn (array $row): float => (float) $row['amount'],
        test()->getJson('/api/v1/approval-requests?sort=amount&direction='.$query)->assertOk()->json('data'),
    );

    expect($amounts('desc'))->toBe([300.0, 200.0, 100.0])
        ->and($amounts('asc'))->toBe([100.0, 200.0, 300.0]);
});

it('hands the screen the ceilings it works under and the choices it draws with', function () {
    nxApQueue($this->companyId, 'JV-000001');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $meta = test()->getJson('/api/v1/approval-requests')->assertOk()->json('meta');

    // The ceilings are read from configuration rather than guessed at, so the
    // warning beside a large document states the figure the server enforces.
    expect($meta['config']['bulk_max_items'])->toBe((int) config('nexas.approvals.bulk_max_items'))
        ->and($meta['config']['bulk_limit'])->toEqual((float) config('nexas.approvals.bulk_limit'))
        ->and($meta['config']['normal_threshold'])->toEqual((float) config('nexas.approvals.normal_threshold'))
        ->and($meta['sortable'])->toBe(['age', 'amount', 'reference', 'type'])
        ->and(collect($meta['document_types'])->pluck('value')->all())
        ->toContain('journal', 'bill', 'payment', 'asset_disposal')
        // The preparer filter offers the people who are actually in the line.
        ->and($meta['preparers'])->toHaveCount(1)
        ->and($meta['preparers'][0])->toHaveKeys(['id', 'name']);
});

/*
|--------------------------------------------------------------------------
| The detail: one document, gathered
|--------------------------------------------------------------------------
*/

it('gathers the whole document for the screen that decides it', function () {
    $ulid = nxApQueue($this->companyId, 'JV-000001', '250.0000');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $data = test()->getJson("/api/v1/approval-requests/{$ulid}/detail")->assertOk()->json('data');

    expect($data['document_missing'])->toBeFalse()
        ->and($data['summary']['document_type_label'])->toBe('Journal')
        ->and($data['summary']['document_reference'])->toBe('JV-000001')
        ->and($data['summary']['can_decide'])->toBeTrue()
        // The preview is the document's own lines, so the reader decides on
        // what it says rather than on a description of it.
        ->and($data['preview']['kind'])->toBe('journal')
        ->and($data['preview']['lines'])->toHaveCount(2)
        ->and($data['preview']['lines'][0]['account'])->toContain('1000')
        ->and($data['financial_impact']['amount'])->toBe('250.0000')
        ->and($data['financial_impact']['balanced'])->toBeTrue()
        ->and($data['attachments'])->toBe([])
        ->and($data['timeline'])->not->toBeEmpty();
});

it('warns the reader about a journal that does not balance and one that has waited too long', function () {
    // A draft is not checked for balance — only posting is — so this is a row
    // the queue can really hold, and the reader is the one who has to notice.
    $unbalanced = $this->makeJournal('JV-000001');
    $this->addLine($unbalanced, 1, $this->cashAccountId, '100.0000', '0');
    $this->addLine($unbalanced, 2, $this->revenueAccountId, '0', '50.0000');

    $ulid = nxApRaise($this->companyId, 'journal', nxApUlid('journals', $unbalanced));

    DB::table('approval_requests')->where('ulid', $ulid)->update(['created_at' => now()->subDays(20)]);

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $data = test()->getJson("/api/v1/approval-requests/{$ulid}/detail")->assertOk()->json('data');
    $codes = collect($data['warnings'])->pluck('code');

    expect($data['financial_impact']['balanced'])->toBeFalse()
        ->and($codes)->toContain('unbalanced')
        ->and($codes)->toContain('stale');
});

it('tells a reader what they may and may not decide', function () {
    $manager = nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    // The Financial Manager raises a document themselves: the queue is what
    // they may read, and the decision is somebody else's.
    Sanctum::actingAs($manager);
    $own = (string) test()->postJson('/api/v1/approval-requests', [
        'document_type' => 'journal',
        'document_ulid' => nxApUlid('journals', $this->balancedJournal('JV-000001')),
    ])->assertCreated()->json('data.ulid');

    $mine = test()->getJson("/api/v1/approval-requests/{$own}/detail")->assertOk()->json('data');

    expect($mine['summary']['raised_by_reader'])->toBeTrue()
        ->and($mine['summary']['can_decide'])->toBeFalse()
        ->and(collect($mine['warnings'])->pluck('code'))->toContain('self_approval');

    // And a decision, once taken, is final: the detail says so rather than
    // offering the buttons again.
    $raised = nxApQueue($this->companyId, 'JV-000002');

    Sanctum::actingAs($manager);
    test()->postJson("/api/v1/approval-requests/{$raised}/approve", [], ['Idempotency-Key' => 'ap-1'])->assertOk();

    $decided = test()->getJson("/api/v1/approval-requests/{$raised}/detail")->assertOk()->json('data');

    expect($decided['summary']['is_pending'])->toBeFalse()
        ->and($decided['summary']['can_decide'])->toBeFalse()
        ->and($decided['summary']['decided_by_name'])->toBe($manager->name)
        ->and(collect($decided['warnings'])->pluck('code'))->toContain('already_decided');
});

/*
|--------------------------------------------------------------------------
| Deciding a batch
|--------------------------------------------------------------------------
*/

it('decides a batch item by item, reporting a refusal rather than discarding the rest', function () {
    $first = nxApQueue($this->companyId, 'JV-000001', '100.0000');
    $second = nxApQueue($this->companyId, 'JV-000002', '200.0000');
    $already = nxApQueue($this->companyId, 'JV-000003', '400.0000');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    // One of the three carries a decision already, so the batch is not clean.
    test()->postJson("/api/v1/approval-requests/{$already}/approve", [], ['Idempotency-Key' => 'before'])->assertOk();

    $result = test()->postJson('/api/v1/approval-requests/bulk-approve', [
        'ulids' => [$first, $second, $already],
    ], ['Idempotency-Key' => 'batch'])->assertOk()->json('data');

    expect($result['requested'])->toBe(3)
        ->and($result['approved'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and($result['value'])->toBe('300.0000');

    $byUlid = collect($result['results'])->keyBy('ulid');

    expect($byUlid[$first]['status'])->toBe('approved')
        ->and($byUlid[$second]['status'])->toBe('approved')
        ->and($byUlid[$first]['document_reference'])->toBe('JV-000001')
        // The item already decided is reported as itself, with the server's own
        // sentence, rather than failing the whole batch.
        ->and($byUlid[$already]['status'])->toBe('failed')
        ->and($byUlid[$already]['message'])->toBeString()->toContain('already');
});

it('refuses a batch whole when it names too many documents or carries too much value', function () {
    $first = nxApQueue($this->companyId, 'JV-000001', '100.0000');
    $second = nxApQueue($this->companyId, 'JV-000002', '200.0000');

    Sanctum::actingAs(nxApMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $payload = ['ulids' => [$first, $second]];

    config()->set('nexas.approvals.bulk_max_items', 1);
    test()->postJson('/api/v1/approval-requests/bulk-approve', $payload, ['Idempotency-Key' => 'too-many'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ApprovalsException');

    config()->set('nexas.approvals.bulk_max_items', 50);
    config()->set('nexas.approvals.bulk_limit', 150.0);
    test()->postJson('/api/v1/approval-requests/bulk-approve', $payload, ['Idempotency-Key' => 'too-valuable'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ApprovalsException');

    // Both ceilings are pre-flight: nothing in either refused batch was decided.
    expect(DB::table('approval_requests')->where('status', 'pending')->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'approval.approved')->count())->toBe(0);
});

it('keeps the batch to the Financial Manager alone', function (string $role) {
    $ulid = nxApQueue($this->companyId, 'JV-000001');

    Sanctum::actingAs(nxApMember($this->companyId, $role));

    $response = test()->postJson('/api/v1/approval-requests/bulk-approve', [
        'ulids' => [$ulid],
    ], ['Idempotency-Key' => 'batch-'.str_replace(' ', '-', $role)]);

    if ($role === AccessMatrix::ROLE_FINANCIAL_MANAGER) {
        $response->assertOk()->assertJsonPath('data.approved', 1);

        return;
    }

    $response->assertForbidden();

    expect(DB::table('approval_requests')->where('ulid', $ulid)->value('status'))->toBe('pending');
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
