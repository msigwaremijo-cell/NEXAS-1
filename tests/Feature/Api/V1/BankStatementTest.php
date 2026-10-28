<?php

use App\Domain\Access\AccessMatrix;
use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A statement import is the one place an outside document reaches these books,
// so every test here uploads a real CSV through the real reader and asserts
// what landed in bank_statement_lines. Nothing on this surface touches a bank:
// the file is one a person downloaded from their own online banking.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();
    $this->makeBankAccountRecord('CRDB Current Account');

    $this->seed(RoleAndPermissionSeeder::class);

    // The statement disk is a private local disk under storage/app/private in
    // production. Faking it keeps a test from writing into the real store while
    // still exercising the same store/read/delete code path.
    Storage::fake('statements');

    // Resolved here rather than in a helper: the fixture's account id is a
    // protected property of the trait, so it can only be read from inside the
    // test case. The route key every request needs is public.
    $this->bankAccountUlid = BankAccount::query()
        ->whereKey($this->bankAccountRecordId)
        ->sole()
        ->ulid;
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxStatementMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxStatementForeignMember(string $role): User
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

    return nxStatementMember($companyId, $role);
}

function nxStatementUpload(int $companyId, string $csv, string $name = 'statement.csv'): array
{
    return test()->postJson(
        '/api/v1/bank-accounts/'.test()->bankAccountUlid.'/statement-uploads',
        ['file' => UploadedFile::fake()->createWithContent($name, $csv)],
    )->json('data');
}

/**
 * A statement with separate money in and money out columns, dated the way a
 * Tanzanian bank writes dates, as CRDB and NMB exports do.
 */
function nxStatementDirectionalCsv(): string
{
    return implode("\n", [
        'Transaction Date,Reference,Description,Money Out,Money In,Balance',
        '15/03/2026,INV-1001,Customer payment received,,1500.0000,1500.0000',
        '18/03/2026,CHQ-0001,Supplier cheque paid,400.0000,,1100.0000',
        '20/03/2026,BANK-CHG,Monthly account charge,12.5000,,1087.5000',
    ])."\n";
}

/**
 * A statement with one signed amount column, where a negative figure is money
 * leaving the account.
 */
function nxStatementSignedCsv(): string
{
    return implode("\n", [
        'Date,Amount,Narration',
        '2026-03-15,1500.00,Customer payment received',
        '2026-03-18,-400.00,Supplier cheque paid',
    ])."\n";
}

/**
 * The mapping that reads a directional statement: separate columns for money in
 * and money out, and no signed amount column at all.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxStatementDirectionalMapping(string $token, array $overrides = []): array
{
    return [
        'upload_token' => $token,
        'date_format' => 'd/m/Y',
        'date_column' => 'Transaction Date',
        'money_in_column' => 'Money In',
        'money_out_column' => 'Money Out',
        'reference_column' => 'Reference',
        'description_column' => 'Description',
        'balance_column' => 'Balance',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxStatementSignedMapping(string $token, array $overrides = []): array
{
    return [
        'upload_token' => $token,
        'date_format' => 'Y-m-d',
        'date_column' => 'Date',
        'amount_column' => 'Amount',
        'description_column' => 'Narration',
        ...$overrides,
    ];
}

function nxStatementImport(array $mapping)
{
    return test()->postJson(
        '/api/v1/bank-accounts/'.test()->bankAccountUlid.'/statement-imports',
        $mapping,
    );
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the statement surface without a token', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/bank-accounts/01JQZ0000000000000000000AB/statement-lines'],
    ['POST', '/api/v1/bank-accounts/01JQZ0000000000000000000AB/statement-uploads'],
    ['POST', '/api/v1/bank-accounts/01JQZ0000000000000000000AB/statement-imports'],
]);

it('lets an accountant import a statement but not a cashier or an auditor', function () {
    $csv = nxStatementDirectionalCsv();

    // A cashier takes money over a counter and reads the accounts. Deciding
    // what an unverified bank claim means is not their job.
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->postJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads', [])
        ->assertForbidden();

    $this->postJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports', [])
        ->assertForbidden();

    // An audit that can write its own raw material is not an audit.
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-lines')->assertOk();
    $this->postJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports', [])
        ->assertForbidden();

    // The accountant is the person who reconciles the bank.
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $token = $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads',
        ['file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)],
    )->assertCreated()->json('data.upload_token');

    nxStatementImport(nxStatementDirectionalMapping($token))->assertCreated();

    expect(DB::table('bank_statement_lines')->where('company_id', $this->companyId)->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Upload and preview
|--------------------------------------------------------------------------
*/

it('stores an uploaded statement and previews its columns without importing a line', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $response = $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads',
        ['file' => UploadedFile::fake()->createWithContent('crdb-march.csv', nxStatementDirectionalCsv())],
    )
        ->assertCreated()
        ->assertJsonPath('data.file_name', 'crdb-march.csv')
        ->assertJsonPath('data.delimiter', ',')
        ->assertJsonPath('data.row_count', 3)
        ->assertJsonPath('data.headers', [
            'Transaction Date', 'Reference', 'Description', 'Money Out', 'Money In', 'Balance',
        ])
        // The first movement is on physical line 2: line 1 is the header.
        ->assertJsonPath('data.preview.0.line', 2)
        ->assertJsonPath('data.preview.0.values.Reference', 'INV-1001')
        ->assertJsonPath('data.preview.0.values.Money In', '1500.0000')
        ->assertJsonPath('data.preview.1.values.Money Out', '400.0000');

    $token = $response->json('data.upload_token');

    // Stored privately, under the company and the account, never in public view.
    expect(Storage::disk('statements')->allFiles($this->companyId.'/'.$this->bankAccountRecordId))
        ->toBe([$this->companyId.'/'.$this->bankAccountRecordId.'/'.$token.'.csv']);

    // Uploading is not importing: nothing has been written to the books' raw
    // material, so the account still holds no statement lines at all.
    expect(DB::table('bank_statement_lines')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('finds the file only through the account it was uploaded to', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    // A second account of the same company: the token is real, but it was not
    // uploaded to this account, so there is no file at that path.
    $savingsLedgerAccountId = $this->makeAccount('1020', 'Bank Savings Account', 'asset', 'debit', true);
    $otherUlid = BankAccount::query()
        ->whereKey($this->makeBankAccountRecord('CRDB Savings Account', 'active', $savingsLedgerAccountId))
        ->sole()
        ->ulid;

    $this->postJson(
        '/api/v1/bank-accounts/'.$otherUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['date_format' => 'd/m/Y']),
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no statement upload'));
});

it('refuses an upload token that was never issued', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A well-formed ULID that this system never handed out.
    nxStatementImport(nxStatementDirectionalMapping('01JQZ0000000000000000000AB'))
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no statement upload'));

    // A token of the right length that is not a ULID: refused by the import
    // before it is ever turned into a path, so caller text cannot be used to
    // walk out of the statement directory.
    nxStatementImport(nxStatementDirectionalMapping(str_repeat('!', 26)))
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no statement upload'));

    // And one that is not even the right length is refused by the request shape
    // before the action is reached at all.
    nxStatementImport(nxStatementDirectionalMapping('../../../etc/passwd'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('upload_token');
});

/*
|--------------------------------------------------------------------------
| Importing
|--------------------------------------------------------------------------
*/

it('imports a money in and money out statement into the account', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token),
    )
        ->assertCreated()
        ->assertJsonPath('data.import_batch', $token)
        ->assertJsonPath('data.imported', 3)
        // Money out of the account is the bank's debit column, money in is its
        // credit column. The books' own sides are the other way round, and that
        // flip is the reconciliation's business rather than the record's.
        ->assertJsonPath('data.lines.0.value_date', '2026-03-15')
        ->assertJsonPath('data.lines.0.reference', 'INV-1001')
        ->assertJsonPath('data.lines.0.description', 'Customer payment received')
        ->assertJsonPath('data.lines.0.debit', '0.0000')
        ->assertJsonPath('data.lines.0.credit', '1500.0000')
        ->assertJsonPath('data.lines.0.balance_after', '1500.0000')
        ->assertJsonPath('data.lines.0.status', 'unmatched')
        ->assertJsonPath('data.lines.1.debit', '400.0000')
        ->assertJsonPath('data.lines.1.credit', '0.0000')
        ->assertJsonPath('data.lines.2.debit', '12.5000')
        ->assertJsonPath('data.lines.2.reference', 'BANK-CHG');

    $rows = DB::table('bank_statement_lines')
        ->where('company_id', $this->companyId)
        ->orderBy('value_date')
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->bank_account_id)->toBe($this->bankAccountRecordId)
        ->and($rows[0]->import_batch)->toBe($token)
        ->and($rows[0]->row_version)->toBe(0)
        // Every line carries a fingerprint of its own content, and no two of
        // these movements share one.
        ->and($rows->pluck('dedupe_hash')->unique())->toHaveCount(3)
        ->and(strlen($rows[0]->dedupe_hash))->toBe(64);

    // Nothing was posted. A statement line is the bank's claim, not an entry.
    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0);

    $this->getJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-lines')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        // Newest first, the way the statement reads down the page.
        ->assertJsonPath('data.0.value_date', '2026-03-20')
        ->assertJsonPath('data.2.value_date', '2026-03-15');

    $this->getJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-lines?from=2026-03-16&to=2026-03-19')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.reference', 'CHQ-0001');
});

it('reads a signed amount column, taking a negative figure as money out', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementSignedCsv())['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementSignedMapping($token),
    )
        ->assertCreated()
        ->assertJsonPath('data.imported', 2)
        ->assertJsonPath('data.lines.0.credit', '1500.0000')
        ->assertJsonPath('data.lines.0.debit', '0.0000')
        ->assertJsonPath('data.lines.1.debit', '400.0000')
        ->assertJsonPath('data.lines.1.credit', '0.0000');
});

it('reads a signed amount column the other way round when the caller says so', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $csv = implode("\n", [
        'Date,Amount,Narration',
        '2026-03-15,1500.00,Customer payment received',
        '2026-03-18,400.00,Supplier cheque paid',
    ])."\n";

    $token = nxStatementUpload($this->companyId, $csv)['upload_token'];

    // An export written from the bank's own side counts the other way round.
    // Guessing here would invert every line in the file, so it is asked for.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementSignedMapping($token, ['positive_is_money_in' => false]),
    )
        ->assertCreated()
        ->assertJsonPath('data.lines.0.debit', '1500.0000')
        ->assertJsonPath('data.lines.0.credit', '0.0000');
});

/*
|--------------------------------------------------------------------------
| Duplicate detection
|--------------------------------------------------------------------------
*/

it('rejects a statement the account has already imported', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $first = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($first),
    )->assertCreated();

    // The same movements, uploaded again as a fresh file: this is the normal
    // case rather than an attack, because banks hand out statements by date
    // range and ranges overlap.
    $second = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($second),
    )
        ->assertStatus(409)
        ->assertJsonPath('error', 'DuplicateStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'already imported'));

    // Nothing was written and the lines already held are untouched: the count
    // is still the three the first import brought in.
    expect(DB::table('bank_statement_lines')->where('company_id', $this->companyId)->count())->toBe(3);
});

it('rejects a file that repeats a movement inside itself', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $csv = implode("\n", [
        'Transaction Date,Reference,Description,Money Out,Money In',
        '15/03/2026,INV-1001,Customer payment received,,1500.0000',
        '18/03/2026,CHQ-0001,Supplier cheque paid,400.0000,',
        // The file has been concatenated with itself, so every line is here
        // twice. Importing it would double every movement in the books.
        '15/03/2026,INV-1001,Customer payment received,,1500.0000',
        '18/03/2026,CHQ-0001,Supplier cheque paid,400.0000,',
    ])."\n";

    $token = nxStatementUpload($this->companyId, $csv)['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['balance_column' => null]),
    )
        ->assertStatus(409)
        ->assertJsonPath('error', 'DuplicateStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'more than once'))
        // The repeats are named by the line they are on, so a person can find
        // them in a spreadsheet: the movement on line 4 repeats line 2, and the
        // one on line 5 repeats line 3.
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'file lines 4, 5'));

    // Refused as a whole: not one of the four movements was written.
    expect(DB::table('bank_statement_lines')->where('company_id', $this->companyId)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses a statement whose dates are not in the layout the mapping names', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    // 15/03/2026 read as a month-first layout is not a date at all, and reading
    // it as the fifteenth of March when it is not would reconcile against the
    // wrong entries.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['date_format' => 'Y-m-d']),
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not a date written as'));

    expect(DB::table('bank_statement_lines')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses a mapping that names a column the file does not carry', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['reference_column' => 'Cheque No']),
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        // The refusal lists what the file does call its columns, so the mapping
        // can be corrected from the response alone.
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no such column')
            && str_contains($message, '"Transaction Date"'));
});

it('refuses a mapping that points two roles at the same column', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, [
            'money_in_column' => 'Balance',
            'money_out_column' => 'Balance',
        ]),
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'two different things'));
});

it('refuses a mapping that describes the money twice or not at all', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxStatementUpload($this->companyId, nxStatementDirectionalCsv())['upload_token'];

    // A signed amount column and a money in column together: a movement
    // described twice cannot be read twice.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['amount_column' => 'Balance']),
    )
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not both'));

    // No amount column of any kind: there is no way to tell what a line moved.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        [
            'upload_token' => $token,
            'date_format' => 'd/m/Y',
            'date_column' => 'Transaction Date',
        ],
    )
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'money in and money out'));
});

it('refuses a comma decimal figure rather than reading it a thousand times too large', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $csv = implode("\n", [
        'Transaction Date,Reference,Description,Money Out,Money In',
        '15/03/2026,INV-1001,Customer payment received,,"1.500,00"',
    ])."\n";

    $token = nxStatementUpload($this->companyId, $csv)['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['balance_column' => null]),
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        // Reading the comma as grouping would make this 1.5, and a
        // reconciliation built on a figure a thousand times too small is worse
        // than one that did not run.
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'comma as the decimal separator'));

    expect(DB::table('bank_statement_lines')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses a line that moves nothing, and a line that moves both ways', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $nothing = implode("\n", [
        'Transaction Date,Reference,Description,Money Out,Money In',
        '15/03/2026,INV-1001,A line with no amount,,',
    ])."\n";

    $token = nxStatementUpload($this->companyId, $nothing)['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['balance_column' => null]),
    )
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'moves no money'));

    $both = implode("\n", [
        'Transaction Date,Reference,Description,Money Out,Money In',
        '15/03/2026,INV-1001,A line on both sides,100.0000,200.0000',
    ])."\n";

    $token = nxStatementUpload($this->companyId, $both)['upload_token'];

    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-imports',
        nxStatementDirectionalMapping($token, ['balance_column' => null]),
    )
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'both the money in and the money out'));
});

it('refuses a file with no movements under its header', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A header and nothing else is refused as soon as the file is read, which
    // happens at upload: no mapping could make it importable, and keeping it
    // would only fill the store with a file nobody can ever import.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads',
        ['file' => UploadedFile::fake()->createWithContent(
            'statement.csv',
            "Transaction Date,Reference,Description,Money Out,Money In\n",
        )],
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no movements under it'));

    expect(Storage::disk('statements')->allFiles($this->companyId.'/'.$this->bankAccountRecordId))->toBe([]);
});

it('refuses an uploaded file it cannot read, and keeps nothing', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A header naming the same column twice: a mapping that referred to it
    // would be ambiguous, so the file is refused before it is kept.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads',
        ['file' => UploadedFile::fake()->createWithContent(
            'statement.csv',
            "Date,Date,Amount\n2026-03-15,2026-03-15,100.00\n",
        )],
    )
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'both named'));

    // A refused file is deleted rather than left in the store for nobody to
    // ever be able to import.
    expect(Storage::disk('statements')->allFiles($this->companyId.'/'.$this->bankAccountRecordId))->toBe([]);

    // A row carrying more values than the header names columns means an
    // unquoted separator, which shifts the narration into the amount.
    $this->postJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads',
        ['file' => UploadedFile::fake()->createWithContent(
            'statement.csv',
            "Date,Amount,Narration\n2026-03-15,100.00,Cheque, paid\n",
        )],
    )
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'holds 4 values'));
});

it('refuses a statement that is not a file at all', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    $this->postJson('/api/v1/bank-accounts/'.$this->bankAccountUlid.'/statement-uploads', [
        'file' => UploadedFile::fake()->createWithContent('statement.csv', ''),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'BankStatementException');
});

/*
|--------------------------------------------------------------------------
| Tenancy
|--------------------------------------------------------------------------
*/

it('answers not found for an account that belongs to another company', function () {
    Sanctum::actingAs(nxStatementMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $ulid = $this->bankAccountUlid;

    Sanctum::actingAs(nxStatementForeignMember(AccessMatrix::ROLE_OWNER));

    // The ULID is real, but not in the caller's company: 404 rather than a 403
    // that would confirm the account exists somewhere else.
    $this->getJson('/api/v1/bank-accounts/'.$ulid.'/statement-lines')->assertNotFound();
    $this->postJson('/api/v1/bank-accounts/'.$ulid.'/statement-uploads', [])->assertNotFound();
    $this->postJson('/api/v1/bank-accounts/'.$ulid.'/statement-imports', [])->assertNotFound();
});
