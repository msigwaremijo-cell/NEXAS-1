<?php

use App\Domain\Access\AccessMatrix;
use App\Models\Attachment;
use App\Models\Journal;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A document filed beside a record. The whole surface is one idea: the record
// is never opened, edited or re-versioned, and the document simply sits next to
// it — which is what lets one be added to a journal that has already been
// posted. Every test here uploads real bytes through the shared validator, so
// what is exercised is the same gate every other upload passes through.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // The attachments disk is a private local disk under storage/app/private in
    // production. Faking it keeps a test from writing into the real store while
    // still exercising the same store/read/delete code path.
    Storage::fake('attachments');
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxAttachmentMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A member of a second company, used to prove that membership of one company is
 * not membership of another.
 */
function nxAttachmentForeignMember(string $role): User
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

    test()->seed(RoleAndPermissionSeeder::class);

    return nxAttachmentMember($companyId, $role);
}

/**
 * Real PDF bytes, enough for finfo to read the file as application/pdf.
 */
function nxAttachmentPdf(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

/**
 * A one-pixel PNG. Used as the contents of a file that is named as a PDF, to
 * prove a file is accepted by what is inside it and not by what it is called.
 */
function nxAttachmentPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
    );
}

/**
 * The public id of a journal fixture, optionally posted.
 */
function nxAttachmentJournal(int $companyId, string $number, bool $posted): Journal
{
    $journalId = test()->balancedJournal($number);

    if ($posted) {
        test()->postJournal($journalId);
    }

    return Journal::query()->whereKey($journalId)->sole();
}

/*
|--------------------------------------------------------------------------
| Filing a document
|--------------------------------------------------------------------------
*/

it('files a document against a posted journal without touching the journal', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0001', posted: true);
    $before = (array) DB::table('journals')->where('id', $journal->getKey())->first();

    $response = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('supplier-invoice.pdf', nxAttachmentPdf()),
    ])
        ->assertCreated()
        ->assertJsonPath('data.attachable_type', 'journal')
        ->assertJsonPath('data.attachable_id', $journal->ulid)
        ->assertJsonPath('data.file_name', 'supplier-invoice.pdf')
        ->assertJsonPath('data.mime_type', 'application/pdf')
        ->assertJsonPath('data.size_bytes', fn (int $size): bool => $size > 0)
        ->assertJsonPath('data.checksum_sha256', fn (string $sum): bool => strlen($sum) === 64)
        ->assertJsonPath('data.download_url', fn (?string $url): bool => is_string($url)
            && str_contains($url, '/api/v1/attachments/'));

    // Stored privately, under a fresh ULID rather than the name it arrived
    // with, so nothing the caller sent decides where the file lands.
    $attachment = Attachment::query()->sole();

    expect($attachment->path)->toStartWith($this->companyId.'/')
        ->and($attachment->path)->toEndWith('.pdf')
        ->and(strtolower($attachment->original_name))->toBe('supplier-invoice.pdf')
        ->and(Storage::disk('attachments')->exists($attachment->path))->toBeTrue()
        ->and(Storage::disk('attachments')->allFiles($this->companyId))->toBe([$attachment->path]);

    // The stored name is the ULID the action minted, never the caller's name.
    $storedBase = basename((string) $attachment->path, '.pdf');
    expect(strlen($storedBase))->toBe(26)
        ->and(strtolower($storedBase))->not->toBe('supplier-invoice');

    // Filing is written into the audit chain.
    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'attachment.added',
        'auditable_type' => Attachment::class,
    ]);

    // And the journal it was filed beside is exactly as it was: still posted,
    // not re-versioned, not touched. Adding a document to a posted record is
    // filing something beside an entry, not editing the entry.
    $after = (array) DB::table('journals')->where('id', $journal->getKey())->first();
    expect($after['status'])->toBe('posted')
        ->and($after['updated_at'])->toBe($before['updated_at'])
        ->and($journal->fresh()->isPosted())->toBeTrue();

    // The link the response handed out works.
    $this->get($response->json('data.download_url'))
        ->assertOk()
        ->assertDownload('supplier-invoice.pdf');
});

it('files a document against a record named by a short type', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0002', posted: false);

    $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('note.pdf', nxAttachmentPdf()),
    ])
        ->assertCreated()
        ->assertJsonPath('data.attachable_type', 'journal');

    // The column keeps the model's own class name, which is what the morph
    // relation resolves against; the short word the caller sent is only a way
    // of naming it.
    expect(Attachment::query()->sole()->attachable_type)->toBe(Journal::class);
});

it('lists the documents filed against a record, newest first', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0003', posted: true);

    foreach (['first.pdf', 'second.pdf'] as $name) {
        $this->postJson('/api/v1/attachments', [
            'attachable_type' => 'journal',
            'attachable_id' => $journal->ulid,
            'file' => UploadedFile::fake()->createWithContent($name, nxAttachmentPdf()),
        ])->assertCreated();
    }

    $this->getJson('/api/v1/attachments?'.http_build_query([
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
    ]))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.file_name', 'second.pdf')
        ->assertJsonPath('data.1.file_name', 'first.pdf');
});

/*
|--------------------------------------------------------------------------
| The store, and getting a file back
|--------------------------------------------------------------------------
*/

it('keeps the document outside public and serves it only through its signed link', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0004', posted: true);

    $url = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertCreated()->json('data.download_url');

    $attachment = Attachment::query()->sole();

    // The disk is rooted outside public and is configured not to be served.
    $root = (string) config('filesystems.disks.attachments.root');
    expect(str_starts_with($root, (string) realpath(public_path())))
        ->toBeFalse('The attachments disk must not live under public.')
        ->and(config('filesystems.disks.attachments.serve'))->toBeFalse();

    // A signature is required: the link is the only way in.
    $unsigned = '/api/v1/attachments/'.$attachment->ulid.'/download';

    $this->get($unsigned)->assertForbidden();

    // A signature that does not match is refused as well.
    $this->get($unsigned.'?expires='.now()->addMinutes(30)->timestamp.'&signature=not-a-signature')
        ->assertForbidden();

    // The signed link works, and hands the file back under the name it was
    // filed with rather than the ULID it is stored as.
    $this->get($url)->assertOk()->assertDownload('receipt.pdf');
});

it('reports a withdrawn document as gone rather than serving a blank', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0005', posted: true);

    $id = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertCreated()->json('data.id');

    // Signed, so the signature is not what refuses it: the document is gone.
    $signed = URL::temporarySignedRoute(
        'api.v1.attachments.download',
        now()->addMinutes(30),
        ['attachment' => $id],
        absolute: false,
    );

    $this->get($signed)->assertOk();

    $this->deleteJson('/api/v1/attachments/'.$id)->assertOk();

    $this->get($signed)->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Refusals — the shared validator
|--------------------------------------------------------------------------
*/

it('refuses a file whose type the company does not accept', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0006', posted: false);

    $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text, not a document'),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'UploadRefusedException')
        // The refusal names what would have been accepted, because the caller is
        // holding the file and can send another one.
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '.txt')
            && str_contains($message, 'pdf'));

    // Nothing was written and nothing was filed.
    expect(Storage::disk('attachments')->allFiles())->toBe([])
        ->and(Attachment::query()->count())->toBe(0);
});

it('refuses a file renamed to look like something it is not', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0007', posted: false);

    // PNG bytes carried under a .pdf name. The extension is one this company
    // accepts; the contents are not, and the contents are what count.
    $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('scan.pdf', nxAttachmentPng()),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'UploadRefusedException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'scan.pdf')
            && str_contains($message, 'image/png'));

    expect(Storage::disk('attachments')->allFiles())->toBe([])
        ->and(Attachment::query()->count())->toBe(0);
});

it('refuses a document with no file at all', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0008', posted: false);

    $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

/*
|--------------------------------------------------------------------------
| Withdrawing a document
|--------------------------------------------------------------------------
*/

it('withdraws a document from a posted record, keeping the row and the audit', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0009', posted: true);

    $id = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertCreated()->json('data.id');

    $attachment = Attachment::query()->sole();

    $this->deleteJson('/api/v1/attachments/'.$id)
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.deleted', true);

    // Soft: the row survives, marked withdrawn, so the account of what was
    // filed beside a posted entry is not erased. The file does not survive.
    $row = DB::table('attachments')->where('ulid', $id)->first();
    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull()
        ->and(Storage::disk('attachments')->exists($attachment->path))->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'attachment.removed',
        'auditable_type' => Attachment::class,
    ]);
});

it('removes a document from a draft record outright', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0010', posted: false);

    $id = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('draft-note.pdf', nxAttachmentPdf()),
    ])->assertCreated()->json('data.id');

    $attachment = Attachment::query()->sole();

    $this->deleteJson('/api/v1/attachments/'.$id)->assertOk();

    // Nothing has been asserted yet that anyone needs to look back at, so the
    // row goes with the file.
    expect(DB::table('attachments')->where('ulid', $id)->exists())->toBeFalse()
        ->and(Storage::disk('attachments')->exists($attachment->path))->toBeFalse();

    // The withdrawal is still written into the audit chain.
    $this->assertDatabaseHas('audit_logs', [
        'company_id' => $this->companyId,
        'action' => 'attachment.removed',
    ]);
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the document surface without a token', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/attachments'],
    ['POST', '/api/v1/attachments'],
    ['GET', '/api/v1/attachments/01JQZ0000000000000000000AB/download'],
    ['DELETE', '/api/v1/attachments/01JQZ0000000000000000000AB'],
]);

it('lets a reader read the shelf but not file to it or withdraw from it', function () {
    $owner = nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);

    $journal = nxAttachmentJournal($this->companyId, 'J-0011', posted: true);

    $id = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertCreated()->json('data.id');

    // A Cashier reads the shelf — reading a document is reading the books — but
    // filing and withdrawing are writes, and this role does not write.
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/attachments?'.http_build_query([
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
    ]))->assertOk()->assertJsonPath('meta.total', 1);

    $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertForbidden();

    $this->deleteJson('/api/v1/attachments/'.$id)->assertForbidden();
});

it('cannot reach another company record or document', function () {
    Sanctum::actingAs(nxAttachmentMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $journal = nxAttachmentJournal($this->companyId, 'J-0012', posted: true);

    $id = $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertCreated()->json('data.id');

    $url = $this->getJson('/api/v1/attachments?'.http_build_query([
        'attachable_type' => 'journal',
        'attachable_id' => $journal->ulid,
    ]))->json('data.0.download_url');

    $journalUlid = $journal->ulid;

    Sanctum::actingAs(nxAttachmentForeignMember(AccessMatrix::ROLE_OWNER));

    // The record exists, but not in the caller's company: 404 rather than a 403
    // that would confirm it exists somewhere else.
    $this->getJson('/api/v1/attachments?'.http_build_query([
        'attachable_type' => 'journal',
        'attachable_id' => $journalUlid,
    ]))->assertNotFound();

    $this->postJson('/api/v1/attachments', [
        'attachable_type' => 'journal',
        'attachable_id' => $journalUlid,
        'file' => UploadedFile::fake()->createWithContent('receipt.pdf', nxAttachmentPdf()),
    ])->assertNotFound();

    // The document is scoped to its own company in the same query that reads
    // it, so a valid signed link is not enough on its own.
    $this->get($url)->assertNotFound();
    $this->deleteJson('/api/v1/attachments/'.$id)->assertNotFound();
});
