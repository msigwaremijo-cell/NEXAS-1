<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Domain\Reporting\Exceptions\UnknownExportTypeException;
use App\Domain\Reporting\Exports\ReportExportRegistry;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// An export is a report in a file, so the point of these tests is not that a
// file appears but that the file says what the report says: the totals in the
// CSV and the PDF are the totals the report endpoint returns. They also pin the
// things that make a file of the books safe to keep — it lives outside public,
// it is only readable through a signed link, that link expires, and only a
// member of the owning company with the report right may follow it.
//
// The fixtures that post to the ledger are raw inserts, for the same reason the
// forecast and cash flow tests use them: which lines count as posted is decided
// by triggers, so a test that wants a posted ledger must write one.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // The export disk is faked so a test never writes into the real store while
    // still exercising the same put/read/delete code path the job uses.
    Storage::fake('exports');
});

/**
 * Write a two-line journal and post it, straight to the tables. The date is
 * written to the journal and to both lines, because a trigger refuses a line
 * that disagrees with its journal's posting date.
 */
function nxExportPost(
    int $companyId,
    int $fiscalYearId,
    int $fiscalPeriodId,
    string $number,
    int $debitAccountId,
    int $creditAccountId,
    string $amount,
    string $date = '2026-03-15',
): void {
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

    foreach ([[1, $debitAccountId, $amount, '0'], [2, $creditAccountId, '0', $amount]] as $line) {
        DB::table('journal_lines')->insert([
            'company_id' => $companyId,
            'journal_id' => $journalId,
            'line_no' => $line[0],
            'account_id' => $line[1],
            'currency_code' => 'TZS',
            'posting_date' => $date,
            'debit' => $line[2],
            'credit' => $line[3],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('journals')->where('id', $journalId)->update([
        'status' => 'posted',
        'posted_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A member of a company, holding exactly one company-scoped role. A null role
 * leaves them a member with no rights at all.
 */
function nxExportMember(int $companyId, ?string $role = null): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    if ($role !== null) {
        setPermissionsTeamId($companyId);
        $user->syncRoles([$role]);
        setPermissionsTeamId(null);
    }

    return $user;
}

/**
 * A second tenant, with a company and a member of its own, used to prove one
 * company's export can never be read from another. The member deliberately
 * holds no role: this fixture is only ever somebody else's caller, and roles
 * are seeded per company, which this company was created after.
 *
 * @return array{company_id: int, user_id: int}
 */
function nxExportForeignCompany(): array
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

    $user = User::factory()->create();
    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    return ['company_id' => $companyId, 'user_id' => (int) $user->getKey()];
}

/**
 * A finished export of the caller's company, with its file already written to
 * the fake disk. Written directly because these tests are about reading and
 * cleaning up files, not about the render that produced them.
 */
function nxExportFinished(int $companyId, int $userId, string $type = 'profit_and_loss', string $format = ExportFormat::Csv->value): Export
{
    $ulid = (string) Str::ulid();
    $path = $companyId.'/'.$ulid.'.'.$format;

    $export = Export::create([
        'ulid' => $ulid,
        'company_id' => $companyId,
        'user_id' => $userId,
        'type' => $type,
        'format' => $format,
        'status' => ExportStatus::Done,
        'parameters' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        'disk' => 'exports',
        'path' => $path,
        'file_name' => $type.'-2026-03-31-'.$ulid.'.'.$format,
        'size_bytes' => 12,
        'started_at' => now(),
        'completed_at' => now(),
        'expires_at' => now()->addHours((int) config('nexas.exports.retention_hours')),
    ]);

    Storage::disk('exports')->put($path, "title,value\nnet,300.0000\n");

    return $export;
}

/**
 * A signed download link for an export, valid for the given number of minutes
 * (negative for one that has already lapsed). Relative, like the link the
 * controller hands back.
 */
function nxExportSignedUrl(string $ulid, int $minutes = 5): string
{
    return URL::temporarySignedRoute(
        'api.v1.exports.download',
        now()->addMinutes($minutes),
        ['export' => $ulid],
        absolute: false,
    );
}

/*
|--------------------------------------------------------------------------
| Ties out: the file says what the report says
|--------------------------------------------------------------------------
*/

it('ties out a profit and loss CSV to the report endpoint', function () {
    nxExportPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100001', $this->receivableAccountId, $this->revenueAccountId, '500.0000');
    nxExportPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100002', $this->expenseAccountId, $this->cashAccountId, '200.0000');

    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $report = $this->getJson('/api/v1/reports/profit-and-loss?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json();

    $this->postJson('/api/v1/exports', [
        'type' => 'profit_and_loss',
        'format' => 'csv',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertStatus(202);

    $ulid = (string) DB::table('exports')->value('ulid');
    $record = $this->getJson('/api/v1/exports/'.$ulid)->assertOk()->json('data');

    expect($record['status'])->toBe('done')
        ->and($record['file_name'])->toEndWith('.csv');

    $csv = $this->get($record['download_url'])->assertOk()->streamedContent();

    // The report's own figures, in the file it produced.
    expect($report['revenue']['total'])->toBe('500.0000')
        ->and($report['expense']['total'])->toBe('200.0000')
        ->and($report['net_profit'])->toBe('300.0000')
        ->and($csv)->toContain('Profit and Loss')
        ->and($csv)->toContain((string) $report['revenue']['total'])
        ->and($csv)->toContain((string) $report['expense']['total'])
        ->and($csv)->toContain((string) $report['net_profit']);
});

it('ties out a trial balance CSV to the report endpoint', function () {
    nxExportPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100001', $this->receivableAccountId, $this->revenueAccountId, '500.0000');
    nxExportPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100002', $this->expenseAccountId, $this->cashAccountId, '200.0000');

    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $report = $this->getJson('/api/v1/reports/trial-balance?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json();

    $this->postJson('/api/v1/exports', [
        'type' => 'trial_balance',
        'format' => 'csv',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertStatus(202);

    $ulid = (string) DB::table('exports')->value('ulid');
    $record = $this->getJson('/api/v1/exports/'.$ulid)->assertOk()->json('data');
    $csv = $this->get($record['download_url'])->assertOk()->streamedContent();

    // The endpoint totals the whole book; the export renders every row of it
    // rather than page one, so the two totals are the same figure.
    expect($csv)->toContain((string) $report['meta']['total_debit'])
        ->and($csv)->toContain((string) $report['meta']['total_credit'])
        ->and($csv)->toContain('4000')
        ->and($csv)->toContain('5000');
});

it('ties out a balance sheet PDF to the report endpoint', function () {
    // Earnings land in cash, so the statement has something on both sides.
    nxExportPost($this->companyId, $this->fiscalYearId, $this->fiscalPeriodId, 'JV-100001', $this->cashAccountId, $this->revenueAccountId, '500.0000');

    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $report = $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-03-31')
        ->assertOk()
        ->json();

    $this->postJson('/api/v1/exports', [
        'type' => 'balance_sheet',
        'format' => 'pdf',
        'as_of' => '2026-03-31',
    ])->assertStatus(202);

    $ulid = (string) DB::table('exports')->value('ulid');
    $record = $this->getJson('/api/v1/exports/'.$ulid)->assertOk()->json('data');

    expect($record['status'])->toBe('done')
        ->and($record['file_name'])->toEndWith('.pdf')
        ->and($record['size_bytes'])->toBeGreaterThan(0);

    $pdf = $this->get($record['download_url'])->assertOk()->streamedContent();

    // A real PDF rather than an empty file with a .pdf name.
    expect($pdf)->toStartWith('%PDF');

    // The PDF prints the document the writer is handed, so the document's own
    // summary is the tie-out: it is built from the same service the endpoint
    // reads, for the same company and date.
    $definition = app(ReportExportRegistry::class)->resolve('balance_sheet');
    $document = ($definition->build)($this->companyId, ['as_of' => '2026-03-31']);

    expect($document->summary)->toBe([
        ['label' => 'Total assets', 'value' => (string) $report['total_assets']],
        ['label' => 'Total liabilities and equity', 'value' => (string) $report['total_liabilities_and_equity']],
        ['label' => 'Balanced', 'value' => $report['balanced'] ? 'Yes' : 'No'],
    ]);
});

/*
|--------------------------------------------------------------------------
| Queued, not rendered on the request
|--------------------------------------------------------------------------
*/

it('queues the render and reports the record before any file exists', function () {
    Queue::fake();

    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/exports', [
        'type' => 'trial_balance',
        'format' => 'csv',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.download_url', null);

    Queue::assertPushed(GenerateExport::class, 1);

    // The request wrote the record and nothing else: the heavy read is the
    // worker's, which is why a worker has to be running for a file to appear.
    expect(Storage::disk('exports')->allFiles())->toBe([])
        ->and(DB::table('exports')->value('status'))->toBe('queued');

    // Run the worker's job and the file arrives. The handler is invoked
    // directly rather than through dispatchSync(): the queue is faked here, so
    // a dispatch — sync or not — is captured rather than run, which is exactly
    // the point being proven above.
    app()->call([new GenerateExport((int) DB::table('exports')->value('id')), 'handle']);

    expect(DB::table('exports')->value('status'))->toBe('done')
        ->and(Storage::disk('exports')->allFiles())->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| The file is only readable through an expiring, checked link
|--------------------------------------------------------------------------
*/

it('serves the file only through a signed link', function () {
    $member = nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    Sanctum::actingAs($member);

    $export = nxExportFinished($this->companyId, (int) $member->getKey());

    // No signature at all.
    $this->getJson('/api/v1/exports/'.$export->ulid.'/download')->assertForbidden();

    // A link signed for this export, still inside its window.
    $this->get(nxExportSignedUrl($export->ulid))
        ->assertOk()
        ->assertHeaderContains('content-type', 'text/csv');
});

it('refuses a link whose lifetime has already passed', function () {
    $member = nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    Sanctum::actingAs($member);

    $export = nxExportFinished($this->companyId, (int) $member->getKey());

    $this->get(nxExportSignedUrl($export->ulid, minutes: -5))->assertForbidden();
});

it('refuses a download of another company\'s export', function () {
    $member = nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    Sanctum::actingAs($member);

    $foreign = nxExportForeignCompany();
    $foreignExport = nxExportFinished($foreign['company_id'], $foreign['user_id']);

    // The link is signed correctly, but the record is not in the caller's
    // company: resolved inside the caller's books, so this is a 404 rather than
    // a 403 that would confirm a file exists somewhere else.
    $this->getJson(nxExportSignedUrl($foreignExport->ulid))->assertNotFound();
});

it('refuses a member without the report right', function () {
    // A member of the company holding no role at all: membership alone is not
    // the right to take the books away as a file.
    $member = nxExportMember($this->companyId);
    Sanctum::actingAs($member);

    $this->getJson('/api/v1/exports')->assertForbidden();
    $this->postJson('/api/v1/exports', ['type' => 'trial_balance', 'format' => 'csv'])->assertForbidden();

    $export = nxExportFinished($this->companyId, (int) $member->getKey());

    // And the same holds once a file exists: the link is not a way around the
    // right.
    $this->getJson(nxExportSignedUrl($export->ulid))->assertForbidden();
});

it('refuses an export named for a company the caller is not a member of', function () {
    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreign = nxExportForeignCompany();

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->postJson('/api/v1/exports', ['type' => 'trial_balance', 'format' => 'csv'])
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

/*
|--------------------------------------------------------------------------
| A render that fails says so
|--------------------------------------------------------------------------
*/

it('marks an export failed with the reason when the render raises', function () {
    $member = nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    Sanctum::actingAs($member);

    // A row whose type the pipeline does not know: the route would refuse this,
    // but a stored type can be removed between the request and the worker, and
    // the job must record that rather than write a file from an unknown report.
    $export = Export::create([
        'company_id' => $this->companyId,
        'user_id' => (int) $member->getKey(),
        'type' => 'no_such_report',
        'format' => ExportFormat::Csv,
        'status' => ExportStatus::Queued,
        'parameters' => [],
        'disk' => 'exports',
    ]);

    expect(fn () => GenerateExport::dispatchSync((int) $export->getKey()))
        ->toThrow(UnknownExportTypeException::class);

    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->error)->toBeString()->toContain('no_such_report')
        ->and($export->path)->toBeNull();

    // And the surface shows it as failed, not as a render still in progress.
    $this->getJson('/api/v1/exports/'.$export->ulid)
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.download_url', null);

    expect($this->getJson('/api/v1/exports/'.$export->ulid)->json('data.error'))->toBeString();
});

it('marks an export failed when the worker dies without recording why itself', function () {
    $member = nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    $export = Export::create([
        'company_id' => $this->companyId,
        'user_id' => (int) $member->getKey(),
        'type' => 'trial_balance',
        'format' => ExportFormat::Csv,
        'status' => ExportStatus::Running,
        'parameters' => [],
        'disk' => 'exports',
    ]);

    // The queue calls failed() when a job exhausts its tries without handle()
    // returning. The row must not sit on running for ever.
    (new GenerateExport((int) $export->getKey()))->failed(new RuntimeException('the worker was killed mid-render'));

    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->error)->toBe('the worker was killed mid-render');
});

/*
|--------------------------------------------------------------------------
| Retention and where the files live
|--------------------------------------------------------------------------
*/

it('keeps export files outside the public directory', function () {
    $root = (string) config('filesystems.disks.exports.root');

    expect($root)->toStartWith(storage_path('app/private'))
        ->and(str_starts_with($root, public_path()))->toBeFalse()
        // Served by the application through the signed route, never by the web
        // server straight off the disk.
        ->and((bool) config('filesystems.disks.exports.serve'))->toBeFalse()
        ->and((int) config('nexas.exports.retention_hours'))->toBeGreaterThan(0)
        ->and((int) config('nexas.exports.link_ttl_minutes'))->toBeGreaterThan(0);
});

it('prunes files past their retention window and keeps the rest', function () {
    $member = nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    $expired = nxExportFinished($this->companyId, (int) $member->getKey());
    $expired->forceFill(['expires_at' => now()->subHour()])->save();

    $current = nxExportFinished($this->companyId, (int) $member->getKey());

    expect(Storage::disk('exports')->exists($expired->path))->toBeTrue()
        ->and(Storage::disk('exports')->exists($current->path))->toBeTrue();

    $this->artisan('exports:prune')->assertSuccessful();

    // The lapsed file is gone; the one still inside its window is not.
    expect(Storage::disk('exports')->exists($expired->path))->toBeFalse()
        ->and(Storage::disk('exports')->exists($current->path))->toBeTrue();

    // The row stays — it is the record of the books being read — but its path
    // is cleared so it can never be served again.
    expect($expired->refresh()->path)->toBeNull()
        ->and($current->refresh()->path)->not->toBeNull();

    // And the download route reports the pruned export as gone rather than
    // ready.
    Sanctum::actingAs($member);
    $this->getJson(nxExportSignedUrl($expired->ulid))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Bad requests
|--------------------------------------------------------------------------
*/

it('refuses a report type the pipeline does not know', function () {
    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/exports', ['type' => 'no_such_report', 'format' => 'csv'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('refuses a window that runs backwards', function () {
    Sanctum::actingAs(nxExportMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/exports', [
        'type' => 'profit_and_loss',
        'format' => 'csv',
        'from' => '2026-03-31',
        'to' => '2026-03-01',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

it('refuses an export without a token', function () {
    $this->getJson('/api/v1/exports')->assertUnauthorized();
    $this->postJson('/api/v1/exports', ['type' => 'trial_balance', 'format' => 'csv'])->assertUnauthorized();
});
