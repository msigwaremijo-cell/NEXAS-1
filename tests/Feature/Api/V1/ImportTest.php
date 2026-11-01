<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Bringing another system's books in as a file is the one place a spreadsheet
// reaches these books, so every test here uploads a real CSV through the real
// reader and asserts what actually landed in the tables. The two opening
// datasets are exercised together, because opening balances is refused unless
// its inventory figure agrees with the layers opening stock laid down.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // The imports disk is a private local disk under storage/app/private in
    // production. Faking it keeps a test from writing into the real store while
    // still exercising the same store/read/delete code path.
    Storage::fake('imports');
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxImportMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxImportForeignMember(string $role): User
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

    return nxImportMember($companyId, $role);
}

/**
 * A warehouse the opening stock file may name by code.
 */
function nxImportWarehouse(int $companyId, string $code = 'MAIN'): int
{
    return DB::table('warehouses')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Main Warehouse',
        'is_default' => true,
        'status' => 'active',
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function nxImportUpload(string $dataset, string $csv, string $name = 'import.csv'): array
{
    return test()->postJson(
        '/api/v1/imports/'.$dataset.'/uploads',
        ['file' => UploadedFile::fake()->createWithContent($name, $csv)],
    )->json('data');
}

/**
 * @param  array<string, string>  $mapping
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportPayload(string $token, array $mapping, array $overrides = []): array
{
    return [
        'upload_token' => $token,
        'mapping' => $mapping,
        ...$overrides,
    ];
}

function nxImportRun(string $dataset, array $payload)
{
    return test()->postJson('/api/v1/imports/'.$dataset.'/runs', $payload);
}

/*
|--------------------------------------------------------------------------
| The files each dataset is fed
|--------------------------------------------------------------------------
*/

function nxImportChartCsv(): string
{
    return implode("\n", [
        'Code,Name,Type,Parent,Postable',
        // The root states its own type; the account under it leaves the type
        // blank and inherits the parent's, which is how a migration export from
        // another system usually reads.
        '7000,Marketing Expense,expense,,yes',
        '7100,Advertising,,7000,yes',
    ])."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportChartPayload(string $token, array $overrides = []): array
{
    return nxImportPayload($token, [
        'code' => 'Code',
        'name' => 'Name',
        'type' => 'Type',
        'parent_code' => 'Parent',
        'postable' => 'Postable',
    ], $overrides);
}

function nxImportCustomerCsv(): string
{
    return implode("\n", [
        'Code,Name,Receivable',
        'CUST-001,Acme Limited,1100',
        'CUST-002,Beta Traders,1100',
    ])."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportCustomerPayload(string $token, array $overrides = []): array
{
    return nxImportPayload($token, [
        'code' => 'Code',
        'name' => 'Name',
        'receivable_account_code' => 'Receivable',
    ], $overrides);
}

function nxImportSupplierCsv(): string
{
    return implode("\n", [
        'Code,Name,Payable,Withholding',
        'SUP-001,Delta Supplies,2000,yes',
        'SUP-002,Epsilon Traders,2000,no',
    ])."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportSupplierPayload(string $token, array $overrides = []): array
{
    return nxImportPayload($token, [
        'code' => 'Code',
        'name' => 'Name',
        'payable_account_code' => 'Payable',
        'withholding_agent' => 'Withholding',
    ], $overrides);
}

function nxImportProductCsv(): string
{
    return implode("\n", [
        'SKU,Name,Type,Inventory,Expense,Unit Cost,Sale Price',
        'WIDGET-1,Widget,goods,1200,5000,5000.00,7500.00',
    ])."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportProductPayload(string $token, array $overrides = []): array
{
    return nxImportPayload($token, [
        'sku' => 'SKU',
        'name' => 'Name',
        'product_type' => 'Type',
        'inventory_account_code' => 'Inventory',
        'expense_account_code' => 'Expense',
        'unit_cost' => 'Unit Cost',
        'sale_price' => 'Sale Price',
    ], $overrides);
}

function nxImportOpeningStockCsv(): string
{
    return implode("\n", [
        'SKU,Warehouse,Quantity,Unit Cost',
        'WIDGET-1,MAIN,10,5000.00',
    ])."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportOpeningStockPayload(string $token, array $overrides = []): array
{
    return nxImportPayload($token, [
        'product_sku' => 'SKU',
        'warehouse_code' => 'Warehouse',
        'quantity' => 'Quantity',
        'unit_cost' => 'Unit Cost',
    ], $overrides);
}

/**
 * An opening journal whose inventory debit agrees with the layers.
 */
function nxImportBalancesCsv(): string
{
    return implode("\n", [
        'Account,Debit,Credit',
        '1200,50000.00,',
        '3000,,50000.00',
    ])."\n";
}

/**
 * The same journal, but opening the inventory account at a figure the layers
 * laid down disagree with.
 */
function nxImportMismatchedBalancesCsv(): string
{
    return implode("\n", [
        'Account,Debit,Credit',
        '1200,60000.00,',
        '3000,,60000.00',
    ])."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxImportBalancesPayload(string $token, array $overrides = []): array
{
    return nxImportPayload($token, [
        'account_code' => 'Account',
        'debit' => 'Debit',
        'credit' => 'Credit',
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the import surface without a token', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['POST', '/api/v1/imports/customers/uploads'],
    ['POST', '/api/v1/imports/customers/runs'],
]);

it('gates every dataset on the rights the same job needs by hand', function (string $dataset, string $role, bool $allowed): void {
    Sanctum::actingAs(nxImportMember($this->companyId, $role));

    // A permitted caller reaches validation, which refuses an upload with no
    // file; a refused one never gets that far. That difference is the matrix.
    $this->postJson('/api/v1/imports/'.$dataset.'/uploads', [])
        ->assertStatus($allowed ? 422 : 403);
})->with([
    // The Owner keeps every right, so every dataset is theirs.
    ['chart_of_accounts', AccessMatrix::ROLE_OWNER, true],
    ['opening_balances', AccessMatrix::ROLE_OWNER, true],
    // An Accountant keeps the chart, the party lists, the stock books and the
    // ledger, so all six are theirs.
    ['chart_of_accounts', AccessMatrix::ROLE_ACCOUNTANT, true],
    ['customers', AccessMatrix::ROLE_ACCOUNTANT, true],
    ['opening_stock', AccessMatrix::ROLE_ACCOUNTANT, true],
    ['opening_balances', AccessMatrix::ROLE_ACCOUNTANT, true],
    // A Finance Officer keeps the same general ledger beside the bills they
    // clear, so the party lists are theirs too.
    ['suppliers', AccessMatrix::ROLE_FINANCE_OFFICER, true],
    // An Inventory Manager keeps the stock books and the party lists they name,
    // but not the chart and not the ledger.
    ['products', AccessMatrix::ROLE_INVENTORY_MANAGER, true],
    ['opening_stock', AccessMatrix::ROLE_INVENTORY_MANAGER, true],
    ['chart_of_accounts', AccessMatrix::ROLE_INVENTORY_MANAGER, false],
    ['customers', AccessMatrix::ROLE_INVENTORY_MANAGER, false],
    ['opening_balances', AccessMatrix::ROLE_INVENTORY_MANAGER, false],
    // A Cashier takes money over a counter and never migrates the books.
    ['customers', AccessMatrix::ROLE_CASHIER, false],
    ['products', AccessMatrix::ROLE_CASHIER, false],
    ['opening_stock', AccessMatrix::ROLE_CASHIER, false],
    ['opening_balances', AccessMatrix::ROLE_CASHIER, false],
    // An audit that can import its own raw material is not an audit.
    ['chart_of_accounts', AccessMatrix::ROLE_AUDITOR, false],
    ['suppliers', AccessMatrix::ROLE_AUDITOR, false],
    // Read-only reads.
    ['products', AccessMatrix::ROLE_READ_ONLY, false],
]);

it('answers not found for a dataset that is not one of the six', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/imports/ledgers/uploads', [])->assertNotFound();
    $this->postJson('/api/v1/imports/ledgers/runs', [])->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Upload and preview
|--------------------------------------------------------------------------
*/

it('stores an uploaded file and previews its columns without importing a row', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $accountsBefore = DB::table('accounts')->where('company_id', $this->companyId)->count();

    $response = $this->postJson(
        '/api/v1/imports/chart_of_accounts/uploads',
        ['file' => UploadedFile::fake()->createWithContent('chart.csv', nxImportChartCsv())],
    )
        ->assertCreated()
        ->assertJsonPath('data.dataset', 'chart_of_accounts')
        ->assertJsonPath('data.file_name', 'chart.csv')
        ->assertJsonPath('data.delimiter', ',')
        ->assertJsonPath('data.row_count', 2)
        ->assertJsonPath('data.headers', ['Code', 'Name', 'Type', 'Parent', 'Postable'])
        // The first data row is on physical line 2: line 1 is the header.
        ->assertJsonPath('data.preview.0.line', 2)
        ->assertJsonPath('data.preview.0.values.Code', '7000')
        ->assertJsonPath('data.preview.1.values.Name', 'Advertising');

    $token = $response->json('data.upload_token');

    // The hash shown is the identity a repeat run will be refused on.
    expect(strlen($response->json('data.file_hash')))->toBe(64);

    // Stored privately, under the company, never in public view.
    expect(Storage::disk('imports')->allFiles((string) $this->companyId))
        ->toBe([$this->companyId.'/'.$token.'.csv']);

    // Uploading is not importing.
    expect(DB::table('accounts')->where('company_id', $this->companyId)->count())->toBe($accountsBefore);
});

it('refuses a file it cannot read, and keeps nothing', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A header and nothing else: no mapping could make it importable.
    $this->postJson('/api/v1/imports/customers/uploads', [
        'file' => UploadedFile::fake()->createWithContent('customers.csv', "Code,Name,Receivable\n"),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no rows under it'));

    expect(Storage::disk('imports')->allFiles((string) $this->companyId))->toBe([]);

    // A header naming the same column twice: a mapping that referred to it
    // would be ambiguous.
    $this->postJson('/api/v1/imports/customers/uploads', [
        'file' => UploadedFile::fake()->createWithContent('customers.csv', "Code,Code\nA,B\n"),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'both named'));

    // A row carrying more values than the header names columns means an
    // unquoted separator, which shifts every later value one column across.
    $this->postJson('/api/v1/imports/customers/uploads', [
        'file' => UploadedFile::fake()->createWithContent('customers.csv', "Code,Name\nA,Acme, Ltd\n"),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'holds 3 values'));
});

it('refuses a file that is not a file at all', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/imports/customers/uploads', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    // An empty file never reaches the import reader: the shared upload
    // validator, which every upload passes through, refuses it first. What it
    // names is the upload, not the import.
    $this->postJson('/api/v1/imports/customers/uploads', [
        'file' => UploadedFile::fake()->createWithContent('customers.csv', ''),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'UploadRefusedException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'empty'));
});

it('finds the file only through the company it was uploaded for', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    // The token is real, but another company is not where it was stored.
    Sanctum::actingAs(nxImportForeignMember(AccessMatrix::ROLE_OWNER));

    nxImportRun('customers', nxImportCustomerPayload($token))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no import upload called'));
});

it('refuses an upload token that was never issued', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A well-formed ULID that this system never handed out.
    nxImportRun('customers', nxImportCustomerPayload('01JQZ0000000000000000000AB'))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no import upload called'));

    // A token of the right length that is not a ULID: refused before it is ever
    // turned into a path, so caller text cannot walk out of the store.
    nxImportRun('customers', nxImportCustomerPayload(str_repeat('!', 26)))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no import upload called'));

    // And one that is not even the right length is refused by the request shape
    // before the action is reached at all.
    nxImportRun('customers', nxImportCustomerPayload('../../../etc/passwd'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('upload_token');
});

/*
|--------------------------------------------------------------------------
| The mapping
|--------------------------------------------------------------------------
*/

it('refuses a mapping that names a column the file does not carry', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token, [
        'mapping' => ['code' => 'Code', 'name' => 'Name', 'receivable_account_code' => 'Ledger'],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        // The refusal lists what the file does call its columns, so the mapping
        // can be corrected from the response alone.
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no such column')
            && str_contains($message, '"Receivable"'));
});

it('refuses a mapping that points two fields at the same column', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token, [
        'mapping' => ['code' => 'Code', 'name' => 'Code', 'receivable_account_code' => 'Receivable'],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'two different things'));
});

it('refuses a mapping that leaves a required column unmapped', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    // A customer cannot be read without the account its balance sits in.
    nxImportRun('customers', nxImportCustomerPayload($token, [
        'mapping' => ['code' => 'Code', 'name' => 'Name'],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'has not named a column for the'));
});

it('refuses a mapping that names a field the importer does not read', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token, [
        'mapping' => [
            'code' => 'Code',
            'name' => 'Name',
            'receivable_account_code' => 'Receivable',
            'loyalty_points' => 'Code',
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'is not something this import reads'));
});

/*
|--------------------------------------------------------------------------
| Importing each dataset
|--------------------------------------------------------------------------
*/

it('imports a chart of accounts as a tree, inheriting the parent type', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('chart_of_accounts', nxImportChartCsv())['upload_token'];

    nxImportRun('chart_of_accounts', nxImportChartPayload($token))
        ->assertCreated()
        ->assertJsonPath('data.dataset', 'chart_of_accounts')
        ->assertJsonPath('data.dry_run', false)
        ->assertJsonPath('data.committed', true)
        ->assertJsonPath('data.rows_total', 2)
        ->assertJsonPath('data.rows_imported', 2)
        ->assertJsonPath('data.errors', [])
        ->assertJsonPath('data.summary.accounts', 2);

    $parent = DB::table('accounts')->where('company_id', $this->companyId)->where('code', '7000')->sole();
    $child = DB::table('accounts')->where('company_id', $this->companyId)->where('code', '7100')->sole();

    expect($parent->depth)->toBe(0)
        ->and($parent->path)->toBeNull()
        // The child states no type; it inherits the parent's.
        ->and($child->account_type)->toBe('expense')
        ->and($child->depth)->toBe(1)
        ->and($child->path)->toBe('7000')
        ->and($child->parent_id)->toBe((int) $parent->id);

    // The run is recorded, which is what a repeat of the same bytes is refused
    // against.
    expect(DB::table('import_runs')->where('company_id', $this->companyId)->where('dataset', 'chart_of_accounts')->count())->toBe(1);
});

it('imports the customer list into the receivable control account', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token))
        ->assertCreated()
        ->assertJsonPath('data.rows_imported', 2)
        ->assertJsonPath('data.summary.customers', 2);

    $rows = DB::table('customers')->where('company_id', $this->companyId)->orderBy('code')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->code)->toBe('CUST-001')
        ->and($rows[0]->name)->toBe('Acme Limited')
        // A blank currency column takes the company's own base currency.
        ->and($rows[0]->currency_code)->toBe('TZS')
        ->and((int) $rows[0]->receivable_account_id)->toBe($this->receivableAccountId)
        ->and($rows[0]->status)->toBe('active')
        ->and((int) $rows[0]->payment_terms_days)->toBe(30);
});

it('imports the supplier list and reads the withholding flag', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('suppliers', nxImportSupplierCsv())['upload_token'];

    nxImportRun('suppliers', nxImportSupplierPayload($token))
        ->assertCreated()
        ->assertJsonPath('data.summary.suppliers', 2);

    $rows = DB::table('suppliers')->where('company_id', $this->companyId)->orderBy('code')->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->payable_account_id)->toBe($this->payableAccountId)
        ->and((bool) $rows[0]->withholding_agent)->toBeTrue()
        ->and((bool) $rows[1]->withholding_agent)->toBeFalse();
});

it('imports the item list, resolving its own inventory and expense accounts', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');

    $token = nxImportUpload('products', nxImportProductCsv())['upload_token'];

    nxImportRun('products', nxImportProductPayload($token))
        ->assertCreated()
        ->assertJsonPath('data.summary.products', 1);

    $product = DB::table('products')->where('company_id', $this->companyId)->where('sku', 'WIDGET-1')->sole();

    expect($product->product_type)->toBe('goods')
        ->and((int) $product->inventory_account_id)->toBe($inventoryAccountId)
        ->and((int) $product->expense_account_id)->toBe($this->expenseAccountId)
        // No unit was named, so the ordinary one is used.
        ->and($product->unit)->toBe('pcs')
        ->and($product->unit_cost)->toEqual('5000.0000')
        ->and($product->sale_price)->toEqual('7500.0000')
        ->and($product->status)->toBe('active');
});

it('imports opening stock as FIFO layers dated the day the count was taken', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $warehouseId = nxImportWarehouse($this->companyId);

    $productToken = nxImportUpload('products', nxImportProductCsv())['upload_token'];
    nxImportRun('products', nxImportProductPayload($productToken))->assertCreated();

    $stockToken = nxImportUpload('opening_stock', nxImportOpeningStockCsv())['upload_token'];

    nxImportRun('opening_stock', nxImportOpeningStockPayload($stockToken, ['as_of' => '2026-03-15']))
        ->assertCreated()
        ->assertJsonPath('data.summary.layers', 1)
        ->assertJsonPath('data.summary.value', '50000.0000');

    $layer = DB::table('stock_layers')->where('company_id', $this->companyId)->sole();

    expect((int) $layer->warehouse_id)->toBe($warehouseId)
        ->and($layer->received_on)->toBe('2026-03-15')
        ->and($layer->quantity_received)->toEqual('10.000000')
        ->and($layer->quantity_remaining)->toEqual('10.000000')
        ->and($layer->unit_cost)->toEqual('5000.0000')
        // There was no movement: this is where the stock already was.
        ->and($layer->receipt_movement_id)->toBeNull();

    // Laying stock down is not a posting.
    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('opens the books with one balanced journal when the figures agree', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $capitalAccountId = $this->makeAccount('3000', 'Owner Capital', 'equity', 'credit');
    nxImportWarehouse($this->companyId);

    nxImportRun(
        'products',
        nxImportProductPayload(nxImportUpload('products', nxImportProductCsv())['upload_token'])
    )->assertCreated();

    nxImportRun(
        'opening_stock',
        nxImportOpeningStockPayload(
            nxImportUpload('opening_stock', nxImportOpeningStockCsv())['upload_token'],
            ['as_of' => '2026-03-15']
        )
    )->assertCreated();

    $balancesToken = nxImportUpload('opening_balances', nxImportBalancesCsv())['upload_token'];

    // The dry run reports the plan's own totals: the two sides the journal is
    // about to open with, before it is written.
    nxImportRun('opening_balances', nxImportBalancesPayload($balancesToken, [
        'dry_run' => true,
        'as_of' => '2026-03-15',
    ]))
        ->assertOk()
        ->assertJsonPath('data.committed', false)
        ->assertJsonPath('data.summary.lines', 2)
        ->assertJsonPath('data.summary.debit', '50000.0000')
        ->assertJsonPath('data.summary.credit', '50000.0000');

    nxImportRun('opening_balances', nxImportBalancesPayload($balancesToken, ['as_of' => '2026-03-15']))
        ->assertCreated()
        ->assertJsonPath('data.rows_imported', 2)
        ->assertJsonPath('data.summary.lines', 2);

    $journal = DB::table('journals')->where('company_id', $this->companyId)->sole();

    expect($journal->status)->toBe('posted')
        ->and($journal->journal_type)->toBe('opening')
        ->and($journal->posting_date)->toBe('2026-03-15');

    $lines = DB::table('journal_lines')->where('journal_id', $journal->id)->orderBy('line_no')->get();

    expect($lines)->toHaveCount(2)
        ->and((int) $lines[0]->account_id)->toBe($inventoryAccountId)
        ->and($lines[0]->debit)->toEqual('50000.0000')
        ->and($lines[0]->credit)->toEqual('0.0000')
        ->and((int) $lines[1]->account_id)->toBe($capitalAccountId)
        ->and($lines[1]->credit)->toEqual('50000.0000');
});

/*
|--------------------------------------------------------------------------
| The opening pair agrees, or nothing is opened
|--------------------------------------------------------------------------
*/

it('refuses opening balances whose inventory figure disagrees with the layers', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->makeAccount('3000', 'Owner Capital', 'equity', 'credit');
    nxImportWarehouse($this->companyId);

    nxImportRun(
        'products',
        nxImportProductPayload(nxImportUpload('products', nxImportProductCsv())['upload_token'])
    )->assertCreated();

    // The stock lays down 10 at 5000.00: fifty thousand.
    nxImportRun(
        'opening_stock',
        nxImportOpeningStockPayload(
            nxImportUpload('opening_stock', nxImportOpeningStockCsv())['upload_token'],
            ['as_of' => '2026-03-15']
        )
    )
        ->assertCreated()
        ->assertJsonPath('data.summary.value', '50000.0000');

    $mismatchedToken = nxImportUpload('opening_balances', nxImportMismatchedBalancesCsv(), 'balances.csv')['upload_token'];

    // The dry run runs the same check, so what an operator approves is what the
    // commit then enforces. Both figures are stated.
    nxImportRun('opening_balances', nxImportBalancesPayload($mismatchedToken, [
        'dry_run' => true,
        'as_of' => '2026-03-15',
    ]))
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.committed', false)
        ->assertJsonPath('data.rows_imported', 0)
        ->assertJsonPath('data.errors.0.line', 0)
        ->assertJsonPath('data.errors.0.message', fn (string $message): bool => str_contains($message, '60000.0000')
            && str_contains($message, '50000.0000'));

    // Committing the same file is refused, and the refusal repeats both figures.
    nxImportRun('opening_balances', nxImportBalancesPayload($mismatchedToken, ['as_of' => '2026-03-15']))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '60000.0000')
            && str_contains($message, '50000.0000'));

    // No journal was posted: the refusal rolled the whole file back.
    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0)
        ->and(DB::table('journal_lines')->where('company_id', $this->companyId)->count())->toBe(0)
        // And no run was recorded, so the corrected file is not a duplicate.
        ->and(DB::table('import_runs')->where('company_id', $this->companyId)->where('dataset', 'opening_balances')->count())->toBe(0);

    // The corrected file agrees with the layers, and opens the books.
    $correctedToken = nxImportUpload('opening_balances', nxImportBalancesCsv(), 'balances-fixed.csv')['upload_token'];

    nxImportRun('opening_balances', nxImportBalancesPayload($correctedToken, ['as_of' => '2026-03-15']))
        ->assertCreated();

    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Row-level refusals
|--------------------------------------------------------------------------
*/

it('refuses a row naming an account the books do not hold', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $csv = implode("\n", [
        'Code,Name,Receivable',
        'CUST-001,Acme Limited,9999',
    ])."\n";

    $token = nxImportUpload('customers', $csv)['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token, ['dry_run' => true]))
        ->assertOk()
        ->assertJsonPath('data.errors.0.field', 'receivable_account_code')
        ->assertJsonPath('data.errors.0.message', fn (string $message): bool => str_contains($message, 'cannot be resolved'));

    // A commit refuses rather than importing the rows that did parse.
    nxImportRun('customers', nxImportCustomerPayload($token))
        ->assertStatus(422)
        ->assertJsonPath('error', 'ImportingException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'to correct'));

    expect(DB::table('customers')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses a file that repeats a code inside itself', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $csv = implode("\n", [
        'Code,Name,Receivable',
        'CUST-001,Acme Limited,1100',
        'CUST-001,Acme Limited,1100',
    ])."\n";

    $token = nxImportUpload('customers', $csv)['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token, ['dry_run' => true]))
        ->assertOk()
        ->assertJsonPath('data.errors.0.message', fn (string $message): bool => str_contains($message, 'more than once'));

    expect(DB::table('customers')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses an opening journal that does not balance', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->makeAccount('3000', 'Owner Capital', 'equity', 'credit');

    $csv = implode("\n", [
        'Account,Debit,Credit',
        '1000,1000.00,',
        '3000,,900.00',
    ])."\n";

    $token = nxImportUpload('opening_balances', $csv)['upload_token'];

    nxImportRun('opening_balances', nxImportBalancesPayload($token, [
        'dry_run' => true,
        'as_of' => '2026-03-15',
    ]))
        ->assertOk()
        ->assertJsonPath('data.errors.0.message', fn (string $message): bool => str_contains($message, 'does not balance'));

    expect(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses an opening stock row that would lay down an empty layer', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    nxImportWarehouse($this->companyId);

    nxImportRun(
        'products',
        nxImportProductPayload(nxImportUpload('products', nxImportProductCsv())['upload_token'])
    )->assertCreated();

    $csv = implode("\n", [
        'SKU,Warehouse,Quantity,Unit Cost',
        'WIDGET-1,MAIN,0,5000.00',
    ])."\n";

    $token = nxImportUpload('opening_stock', $csv)['upload_token'];

    nxImportRun('opening_stock', nxImportOpeningStockPayload($token, [
        'dry_run' => true,
        'as_of' => '2026-03-15',
    ]))
        ->assertOk()
        ->assertJsonPath('data.errors.0.message', fn (string $message): bool => str_contains($message, 'empty stock layer'));

    expect(DB::table('stock_layers')->where('company_id', $this->companyId)->count())->toBe(0);
});

it('refuses an opening dataset that was not told the date its figures are as at', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('opening_stock', nxImportOpeningStockCsv())['upload_token'];

    nxImportRun('opening_stock', nxImportOpeningStockPayload($token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('as_of');
});

/*
|--------------------------------------------------------------------------
| Duplicate detection
|--------------------------------------------------------------------------
*/

it('rejects a file these books have already imported', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $first = nxImportUpload('customers', nxImportCustomerCsv(), 'customers.csv')['upload_token'];

    // The name given to the run is recorded with it, which is what lets the
    // refusal below name the file it means rather than saying "unnamed file".
    nxImportRun('customers', nxImportCustomerPayload($first, ['file_name' => 'customers.csv']))
        ->assertCreated();

    // The same bytes, uploaded again as a fresh file: this is the ordinary
    // mistake, not an attack, because a corrected export is often re-run.
    $second = nxImportUpload('customers', nxImportCustomerCsv(), 'customers.csv')['upload_token'];

    // The refusal names both the dataset and the file it means, rather than
    // "unnamed file", because the name was recorded with the first run.
    $refusal = nxImportRun('customers', nxImportCustomerPayload($second));

    $refusal
        ->assertStatus(409)
        ->assertJsonPath('error', 'DuplicateImportException');

    expect($refusal->json('message'))
        ->toContain('already been imported')
        ->toContain('customers.csv');

    // Nothing was written a second time.
    expect(DB::table('customers')->where('company_id', $this->companyId)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Dry run
|--------------------------------------------------------------------------
*/

it('reports what a commit would do without writing anything', function () {
    Sanctum::actingAs(nxImportMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $token = nxImportUpload('customers', nxImportCustomerCsv())['upload_token'];

    nxImportRun('customers', nxImportCustomerPayload($token, ['dry_run' => true]))
        ->assertOk()
        ->assertJsonPath('data.dry_run', true)
        ->assertJsonPath('data.committed', false)
        ->assertJsonPath('data.rows_total', 2)
        ->assertJsonPath('data.rows_valid', 2)
        ->assertJsonPath('data.rows_imported', 0)
        ->assertJsonPath('data.summary.customers', 2);

    expect(DB::table('customers')->where('company_id', $this->companyId)->count())->toBe(0)
        // A dry run records no run, so the same file may still be committed.
        ->and(DB::table('import_runs')->where('company_id', $this->companyId)->count())->toBe(0);

    nxImportRun('customers', nxImportCustomerPayload($token))->assertCreated();

    expect(DB::table('customers')->where('company_id', $this->companyId)->count())->toBe(2);
});
