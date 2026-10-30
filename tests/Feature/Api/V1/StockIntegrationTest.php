<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\Company;
use App\Models\GrniAllocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Stage 8B joins the stock books to the buying and selling books. Neither side
// is stubbed: a purchase is a receipt and then a bill, a sale is an invoice
// that also issues the goods, and the only way to show that a bill never
// receives the stock a second time is to post both and read the layers, the
// allocations and the ledger back.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // Stock is carried at 1200 and the cost of what leaves lands on 5100. A
    // purchase is met by 2100 until the bill for it arrives, and any difference
    // between the two lands on 5200.
    $this->inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->cogsAccountId = $this->makeAccount('5100', 'Cost of Sales', 'expense', 'debit');
    $this->grniAccountId = $this->makeAccount('2100', 'Goods Received Not Invoiced', 'liability', 'credit');
    $this->varianceAccountId = $this->makeAccount('5200', 'Purchase Price Variance', 'expense', 'debit');
    $this->capitalAccountId = $this->makeAccount('3000', 'Owner Capital', 'equity', 'credit');

    $this->productId = nxS8bProduct($this->companyId);
    // Deliberately not the default: most tests below never sell anything, and
    // the ones that do say so by asking for a default warehouse.
    $this->warehouseId = nxS8bWarehouse($this->companyId, 'MAIN', 'Main Store');
    $this->supplierId = nxS8bSupplier($this->companyId, $this->payableAccountId);

    // The helpers below are plain functions, and a plain function cannot read
    // the trait's protected properties, so the few accounts they name are
    // copied onto ones they can.
    $this->billExpenseAccountId = $this->expenseAccountId;
    $this->invoiceRevenueAccountId = $this->revenueAccountId;
    $this->customerReceivableAccountId = $this->receivableAccountId;
    $this->supplierPayableAccountId = $this->payableAccountId;

    // A company that can buy, with the two purchase accounts named where the
    // settings screen writes them.
    nxS8bSettings($this->companyId, [
        'inventory_costing' => 'fifo',
        'allow_negative_stock' => false,
        'grni_account_id' => $this->grniAccountId,
        'purchase_variance_account_id' => $this->varianceAccountId,
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxS8bMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxS8bForeignCompanyId(): int
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
 * An item that holds stock, naming the two accounts its own movements post
 * through.
 *
 * @param  array<string, mixed>  $overrides
 */
function nxS8bProduct(int $companyId, array $overrides = []): int
{
    return (int) Product::create([
        'company_id' => $companyId,
        'sku' => 'SKU-'.Str::upper(Str::random(6)),
        'name' => 'Test Widget',
        'unit' => 'pcs',
        'product_type' => 'goods',
        'inventory_account_id' => test()->inventoryAccountId,
        'expense_account_id' => test()->cogsAccountId,
        'status' => 'active',
        ...$overrides,
    ])->getKey();
}

/**
 * Naming a default demotes whichever warehouse held it, the way the warehouses
 * screen does, so the company never holds two candidates at once.
 */
function nxS8bWarehouse(int $companyId, string $code, string $name, bool $isDefault = false): int
{
    if ($isDefault) {
        Warehouse::query()->where('company_id', $companyId)->update(['is_default' => false]);
    }

    return (int) Warehouse::create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'is_default' => $isDefault,
    ])->getKey();
}

function nxS8bDefaultWarehouse(int $companyId): int
{
    return nxS8bWarehouse($companyId, 'DEFAULT', 'Sales Store', true);
}

function nxS8bSupplier(int $companyId, int $payableAccountId, string $code = 'S-001'): int
{
    return DB::table('suppliers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
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
 * The choices a company has made about its stock, written where the policy
 * looks for them.
 *
 * @param  array<string, mixed>  $settings
 */
function nxS8bSettings(int $companyId, array $settings): void
{
    Company::query()->whereKey($companyId)->firstOrFail()->update(['settings' => $settings]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxS8bReceiptBody(array $overrides = []): array
{
    return [
        'product_id' => test()->productId,
        'warehouse_id' => test()->warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '10',
        'unit_cost' => '10.0000',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxS8bBillBody(int $supplierId, array $overrides = []): array
{
    return [
        'supplier_id' => $supplierId,
        'bill_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => test()->productId,
            'account_id' => test()->billExpenseAccountId,
            'quantity' => '10',
            'unit_price' => '10.0000',
        ]],
        ...$overrides,
    ];
}

/**
 * A bill taken as far as it can go without being posted.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function nxS8bApprovedBill(array $body): array
{
    $draft = test()->postJson('/api/v1/bills', $body)->assertCreated()->json('data');

    test()->postJson('/api/v1/bills/'.$draft['ulid'].'/submit')->assertOk();

    // A bill is approved by a second pair of hands, never by the member who
    // entered it, so the helper clears it under a separate member and then puts
    // the caller back — the tests around it go on to post and read as that
    // caller.
    $enteredBy = Auth::user();
    Sanctum::actingAs(nxS8bMember($enteredBy->defaultCompanyId(), AccessMatrix::ROLE_FINANCE_OFFICER));
    test()->postJson('/api/v1/bills/'.$draft['ulid'].'/approve')->assertOk();
    Sanctum::actingAs($enteredBy);

    return $draft;
}

/**
 * @return array<string, mixed>
 */
function nxS8bPostBill(string $ulid): array
{
    return test()->postJson('/api/v1/bills/'.$ulid.'/post')->assertOk()->json('data');
}

/**
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function nxS8bInvoice(array $body): array
{
    return test()->postJson('/api/v1/invoices', $body)->assertCreated()->json('data');
}

/**
 * The ledger figures for one account, read back through the reporting engine
 * rather than from the tables the test just wrote.
 *
 * @return array{debit: string, credit: string, closing: string}
 */
function nxS8bLedger(int $accountId): array
{
    $response = test()->getJson('/api/v1/reports/general-ledger?account_id='.$accountId)->assertOk();

    return [
        'debit' => (string) $response->json('meta.period_debit'),
        'credit' => (string) $response->json('meta.period_credit'),
        'closing' => (string) $response->json('meta.closing_balance'),
    ];
}

/*
|--------------------------------------------------------------------------
| The purchase accounts
|--------------------------------------------------------------------------
*/

it('refuses a goods received account that is not a liability', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // 1200 is inventory: an asset. What the company owes for goods it has not
    // been invoiced for cannot be carried there, so the rule says liability or
    // it is not the account the setting claims it is.
    $this->patchJson('/api/v1/company/inventory-settings', ['grni_account_id' => $this->inventoryAccountId])
        ->assertStatus(422)
        ->assertJsonValidationErrors('grni_account_id')
        ->assertJsonPath('errors.grni_account_id.0', fn (string $message): bool => str_contains($message, 'liability'));

    // A refused write leaves the setting as it was rather than half-applied.
    expect(app(StockPolicy::class)->grniAccountId(Company::query()->findOrFail($this->companyId)))
        ->toBe($this->grniAccountId);
});

it('refuses a purchase account that belongs to another company', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreignAccountId = DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => nxS8bForeignCompanyId(),
        'code' => '2100',
        'name' => 'Goods Received Not Invoiced',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The account is a real liability account, but not one of the caller's:
    // accepting it would credit a purchase into another tenant's ledger.
    $this->patchJson('/api/v1/company/inventory-settings', ['grni_account_id' => $foreignAccountId])
        ->assertStatus(422)
        ->assertJsonValidationErrors('grni_account_id');
});

it('refuses a purchase account that is no longer in use', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $retired = $this->makeAccount('2199', 'Old Goods Received', 'liability', 'credit');
    DB::table('accounts')->where('id', $retired)->update(['status' => 'inactive']);

    // A closed account would take postings nobody is watching, so naming one is
    // refused at the settings screen rather than at the first receipt.
    $this->patchJson('/api/v1/company/inventory-settings', ['grni_account_id' => $retired])
        ->assertStatus(422)
        ->assertJsonValidationErrors('grni_account_id');
});

it('writes one audit entry when either purchase account changes, and none when they do not', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The variance account starts unset, so both settings are real changes.
    nxS8bSettings($this->companyId, ['inventory_costing' => 'fifo']);

    $this->patchJson('/api/v1/company/inventory-settings', [
        'grni_account_id' => $this->grniAccountId,
        'purchase_variance_account_id' => $this->varianceAccountId,
    ])
        ->assertOk()
        ->assertJsonPath('data.grni_account_id', $this->grniAccountId)
        ->assertJsonPath('data.purchase_variance_account_id', $this->varianceAccountId);

    // Which account a purchase credits and which one a price difference lands
    // on is an accounting decision, so it is written to the chain where it can
    // be audited rather than left in the settings blob.
    $logs = DB::table('audit_logs')->where('action', 'company.inventory_settings.updated')->get();

    expect($logs)->toHaveCount(1)
        ->and((int) $logs[0]->company_id)->toBe($this->companyId);

    expect(json_decode((string) $logs[0]->after, true))->toMatchArray([
        'grni_account_id' => $this->grniAccountId,
        'purchase_variance_account_id' => $this->varianceAccountId,
    ]);

    expect(json_decode((string) $logs[0]->before, true))->toMatchArray([
        'grni_account_id' => null,
        'purchase_variance_account_id' => null,
    ]);

    // Writing the same accounts back says nothing new, so it is not a change
    // and does not append a second entry to the chain.
    $this->patchJson('/api/v1/company/inventory-settings', [
        'grni_account_id' => $this->grniAccountId,
        'purchase_variance_account_id' => $this->varianceAccountId,
    ])->assertOk();

    expect(DB::table('audit_logs')->where('action', 'company.inventory_settings.updated')->count())
        ->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Receipts: what a purchase credits
|--------------------------------------------------------------------------
*/

it('credits goods received not invoiced when a receipt names a supplier', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A counter account is sent as well, and ignored: a purchase is met by what
    // the company owes for the goods, whatever the caller would rather credit.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId,
        'counter_account_id' => $this->capitalAccountId,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.movement_type', 'receipt')
        ->assertJsonPath('data.quantity', '10.000000')
        ->assertJsonPath('data.total_cost', '100.0000');

    // Dr inventory 100, Cr goods received not invoiced 100.
    expect(nxS8bLedger($this->inventoryAccountId))->toMatchArray(['debit' => '100.0000', 'credit' => '0.0000'])
        ->and(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '100.0000'])
        ->and(nxS8bLedger($this->capitalAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '0.0000']);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('meets a receipt that names no supplier by the counter account, and never touches goods received', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Opening stock: the goods are real, but nobody delivered them on a bill,
    // so what they are worth has to come from somewhere the caller names.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'counter_account_id' => $this->capitalAccountId,
    ]))->assertCreated();

    expect(nxS8bLedger($this->capitalAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '100.0000'])
        ->and(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '0.0000']);

    // And having credited nothing there, there is nothing for a bill to clear:
    // a receipt with no supplier is nobody's delivery.
    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));

    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'received and unbilled'));
});

it('requires a receipt that names no supplier to say what it was met by', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody())
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'counter account'));

    expect(StockMovement::query()->count())->toBe(0);
});

it('records the supplier on a purchase receipt and on nothing else', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $purchase = $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId,
    ]))->assertCreated()->json('data');

    // Opening stock, which is not a purchase.
    $opening = $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'counter_account_id' => $this->capitalAccountId,
    ]))->assertCreated()->json('data');

    $issue = $this->postJson('/api/v1/stock/issues', [
        'product_id' => $this->productId,
        'warehouse_id' => $this->warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '1',
    ])->assertCreated()->json('data');

    // A promise to one supplier is about one delivery, so the column is set on
    // a purchase and left null everywhere else rather than guessed at.
    expect(DB::table('stock_movements')->where('id', $purchase['id'])->value('supplier_id'))->toBe($this->supplierId)
        ->and(DB::table('stock_movements')->where('id', $opening['id'])->value('supplier_id'))->toBeNull()
        ->and(DB::table('stock_movements')->where('id', $issue['id'])->value('supplier_id'))->toBeNull();
});

it('refuses a purchase when the company names no goods received or variance account', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Neither account set.
    nxS8bSettings($this->companyId, ['inventory_costing' => 'fifo']);

    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody(['supplier_id' => $this->supplierId]))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no goods received not invoiced account'));

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));

    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no goods received not invoiced account'));

    // Goods received named but the variance account missing: both are asked for
    // as a pair, so the company finds out now rather than on the first bill
    // whose price disagrees with the delivery.
    nxS8bSettings($this->companyId, [
        'inventory_costing' => 'fifo',
        'grni_account_id' => $this->grniAccountId,
    ]);

    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody(['supplier_id' => $this->supplierId]))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no purchase variance account'));

    expect(StockMovement::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The bill clears goods received: the rule Stage 8B exists for
|--------------------------------------------------------------------------
*/

it('clears goods received not invoiced when a bill for a stock item is posted, and receives nothing twice', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId,
        'quantity' => '10',
        'unit_cost' => '10.0000',
    ]))->assertCreated();

    $movementsBefore = StockMovement::query()->count();
    $layersBefore = (float) DB::table('stock_layers')->sum('quantity_received');

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));
    nxS8bPostBill($bill['ulid']);

    // The whole rule in two lines: the bill receives nothing, so no movement is
    // written and no layer is opened. If it had received the goods as well, the
    // quantity on hand and the valuation would both be double what arrived.
    expect(StockMovement::query()->count())->toBe($movementsBefore)
        ->and((float) DB::table('stock_layers')->sum('quantity_received'))->toBe($layersBefore);

    // Dr goods received 100, Cr the supplier's payable 100: the liability moves
    // from "goods in, no invoice" to "owed to the supplier".
    expect(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '100.0000', 'credit' => '100.0000', 'closing' => '0.0000'])
        ->and(nxS8bLedger($this->payableAccountId))->toMatchArray(['credit' => '100.0000']);

    // And what was cleared is written down, not merely intended.
    expect(GrniAllocation::query()->count())->toBe(1)
        ->and(GrniAllocation::query()->sole()->quantity)->toBe('10.000000');
});

it('refuses to post a bill for a stock item that has not been received', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));

    // Nothing arrived, so there is nothing to clear. Posting it anyway would be
    // the very thing this stage forbids: a bill that receives the stock itself.
    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'receive the goods before billing for them'));

    expect(StockMovement::query()->count())->toBe(0)
        ->and(GrniAllocation::query()->count())->toBe(0)
        ->and(nxS8bLedger($this->grniAccountId)['debit'])->toBe('0.0000');
});

it('clears the oldest open receipts first and refuses to clear more than was received and unbilled', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // 50 at 10.00, then 50 at 14.00.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '50', 'unit_cost' => '10.0000',
    ]))->assertCreated();

    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '50', 'unit_cost' => '14.0000',
    ]))->assertCreated();

    // 60 billed at 10.00 = 600 net against the deliveries. The oldest receipt is
    // drawn down first, so 50 comes off the 10.00 delivery and 10 off the 14.00
    // one: 500 + 140 = 640 cleared.
    $first = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->expenseAccountId,
            'quantity' => '60',
            'unit_price' => '10.0000',
        ]],
    ]));

    nxS8bPostBill($first['ulid']);

    $allocations = GrniAllocation::query()->orderBy('id')->get();

    expect($allocations)->toHaveCount(2)
        ->and($allocations[0]->quantity)->toBe('50.000000')
        ->and($allocations[0]->unit_cost)->toBe('10.0000')
        ->and($allocations[0]->value)->toBe('500.0000')
        ->and($allocations[1]->quantity)->toBe('10.000000')
        ->and($allocations[1]->unit_cost)->toBe('14.0000')
        ->and($allocations[1]->value)->toBe('140.0000');

    // The bill is worth 600 but cleared 640, so the 40 the delivery was dearer
    // by is credited back to the variance account.
    expect(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '640.0000'])
        ->and(nxS8bLedger($this->varianceAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '40.0000'])
        ->and(nxS8bLedger($this->payableAccountId))->toMatchArray(['credit' => '600.0000']);

    // 40 of the second delivery is still open. Asking for 50 is more than that.
    $greedy = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->expenseAccountId,
            'quantity' => '50',
            'unit_price' => '14.0000',
        ]],
    ]));

    $this->postJson('/api/v1/bills/'.$greedy['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'received and unbilled, and this bill line asks to clear'));

    // Refused as a whole: the refusal left no allocation behind.
    expect(GrniAllocation::query()->count())->toBe(2);

    // The 40 that is really open is billable, and after it nothing is.
    $rest = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->expenseAccountId,
            'quantity' => '40',
            'unit_price' => '14.0000',
        ]],
    ]));

    nxS8bPostBill($rest['ulid']);

    expect((float) GrniAllocation::query()->sum('quantity'))->toBe(100.0)
        // Every delivery has been billed and goods received is square.
        ->and(nxS8bLedger($this->grniAccountId)['closing'])->toBe('0.0000');

    $again = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));

    $this->postJson('/api/v1/bills/'.$again['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'receive the goods before billing for them'));
});

it('bills a receipt in parts, and one receipt can settle two bills', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // One delivery of 10 at 5.00, billed in two parts.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '10', 'unit_cost' => '5.0000',
    ]))->assertCreated();

    $line = static fn (string $quantity): array => [[
        'description' => 'Widgets',
        'product_id' => test()->productId,
        'account_id' => test()->billExpenseAccountId,
        'quantity' => $quantity,
        'unit_price' => '5.0000',
    ]];

    $part = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, ['lines' => $line('6')]));
    nxS8bPostBill($part['ulid']);

    // 6 of the 10 are cleared, and the other 4 are still open rather than being
    // assumed settled along with them.
    expect((float) GrniAllocation::query()->sum('quantity'))->toBe(6.0);

    $rest = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, ['lines' => $line('4')]));
    nxS8bPostBill($rest['ulid']);

    // Two bills, one receipt, and between them the whole delivery is settled
    // exactly once.
    expect((float) GrniAllocation::query()->sum('quantity'))->toBe(10.0)
        ->and(GrniAllocation::query()->distinct()->count('receipt_movement_id'))->toBe(1)
        ->and(nxS8bLedger($this->grniAccountId)['closing'])->toBe('0.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('posts a dearer bill to purchase variance and leaves the receipt alone', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Received at 10.00, billed at 12.00.
    $receipt = $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '10', 'unit_cost' => '10.0000',
    ]))->assertCreated()->json('data');

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->expenseAccountId,
            'quantity' => '10',
            'unit_price' => '12.0000',
        ]],
    ]));

    nxS8bPostBill($bill['ulid']);

    // Dr goods received 100 (what was booked), Dr variance 20 (the difference),
    // Cr the supplier 120 (what the bill says).
    expect(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '100.0000', 'credit' => '100.0000'])
        ->and(nxS8bLedger($this->varianceAccountId))->toMatchArray(['debit' => '20.0000', 'credit' => '0.0000'])
        ->and(nxS8bLedger($this->payableAccountId))->toMatchArray(['credit' => '120.0000']);

    // The delivery is what it was — the price difference does not restate the
    // layer, because what the stock cost when it arrived is a fact.
    expect(DB::table('stock_movements')->where('id', $receipt['id'])->value('total_cost'))->toBe('100.0000')
        ->and(DB::table('stock_layers')->sole()->unit_cost)->toBe('10.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('credits purchase variance when the bill is cheaper than the receipt', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Received at 12.00, billed at 10.00: the company owes less than the goods
    // are carried at, so the difference comes back as a credit.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '10', 'unit_cost' => '12.0000',
    ]))->assertCreated();

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));

    nxS8bPostBill($bill['ulid']);

    expect(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '120.0000'])
        ->and(nxS8bLedger($this->varianceAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '20.0000'])
        ->and(nxS8bLedger($this->payableAccountId))->toMatchArray(['credit' => '100.0000']);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('refuses to clear another supplier receipt', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $other = nxS8bSupplier($this->companyId, $this->payableAccountId, 'S-002');

    // The same item, delivered by S-001.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '10', 'unit_cost' => '10.0000',
    ]))->assertCreated();

    // S-002's bill says nothing about S-001's delivery: a liability is owed to
    // one supplier for one item, so clearing across the two would settle a debt
    // that is not this bill's.
    $bill = nxS8bApprovedBill(nxS8bBillBody($other));

    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'receive the goods before billing for them'));

    expect(GrniAllocation::query()->count())->toBe(0)
        ->and(nxS8bLedger($this->grniAccountId))->toMatchArray(['debit' => '0.0000', 'credit' => '100.0000']);
});

/*
|--------------------------------------------------------------------------
| Correction
|--------------------------------------------------------------------------
*/

it('refuses to reverse a receipt a bill has already cleared', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $receipt = $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '10', 'unit_cost' => '10.0000',
    ]))->assertCreated()->json('data');

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));
    nxS8bPostBill($bill['ulid']);

    // Half the delivery has been billed. Undoing the receipt now would leave
    // the clearing pointing at stock that had never arrived, so the bill is
    // corrected first and the receipt only after it.
    $this->postJson('/api/v1/stock/movements/'.$receipt['ulid'].'/reversal')
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'already cleared'));

    expect(DB::table('stock_movements')->where('id', $receipt['id'])->value('status'))->toBe('posted')
        ->and(GrniAllocation::query()->count())->toBe(1);
});

it('does not offer a reversed receipt to a later bill', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $receipt = $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'supplier_id' => $this->supplierId, 'quantity' => '10', 'unit_cost' => '10.0000',
    ]))->assertCreated()->json('data');

    // Nothing has been billed, so the receipt can still be corrected.
    $this->postJson('/api/v1/stock/movements/'.$receipt['ulid'].'/reversal')
        ->assertCreated()
        ->assertJsonPath('data.movement_type', 'issue');

    // A receipt that took the goods back is no longer a delivery owed for, so
    // the bill that follows has nothing to clear.
    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId));

    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'receive the goods before billing for them'));

    expect(GrniAllocation::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Sales: the invoice issues the goods
|--------------------------------------------------------------------------
*/

it('refuses to issue an invoice for a stock item when the company names no default warehouse', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $this->receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = nxS8bInvoice([
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '100.0000',
        ]],
    ]);

    // Warehouses exist, but none of them is the one a sale draws from, so there
    // is nowhere to take the goods off. The refusal names the fix rather than
    // leaving the caller to guess where the stock went.
    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'names no default warehouse'));

    // Refused as a whole: no entry, no movement, and the document is still a
    // draft that can be corrected.
    expect(DB::table('journals')->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);

    $this->getJson('/api/v1/invoices/'.$invoice['ulid'])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.journal_id', null);
});

it('issues the goods from the default warehouse when an invoice for a stock item is issued', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $warehouseId = nxS8bDefaultWarehouse($this->companyId);

    // 10 on the shelf at 5.00, met by capital because nobody delivered them.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'warehouse_id' => $warehouseId,
        'quantity' => '10',
        'unit_cost' => '5.0000',
        'counter_account_id' => $this->capitalAccountId,
    ]))->assertCreated();

    $customerId = DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $this->receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = nxS8bInvoice([
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '100.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')
        ->assertOk()
        ->assertJsonPath('data.status', 'issued');

    // The goods went out of the default warehouse, priced by the layer they came
    // from: 4 x 5 = 20.
    $issue = StockMovement::query()->where('movement_type', 'issue')->sole();

    expect((int) $issue->warehouse_id)->toBe($warehouseId)
        ->and((int) $issue->product_id)->toBe($this->productId)
        ->and($issue->quantity)->toBe('-4.000000')
        ->and($issue->total_cost)->toBe('20.0000');

    // Dr cost of sales 20, Cr inventory 20 — beside the invoice's own entry,
    // which is the customer owing 400 against revenue.
    expect(nxS8bLedger($this->cogsAccountId))->toMatchArray(['debit' => '20.0000'])
        ->and(nxS8bLedger($this->inventoryAccountId))->toMatchArray(['debit' => '50.0000', 'credit' => '20.0000', 'closing' => '30.0000'])
        ->and(nxS8bLedger($this->receivableAccountId))->toMatchArray(['debit' => '400.0000'])
        ->and(nxS8bLedger($this->revenueAccountId))->toMatchArray(['credit' => '400.0000']);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);

    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '30.0000')
        ->assertJsonPath('reconciled', true);
});

it('issues nothing for an invoice made only of services', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $this->receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // No product at all: consulting is not on a shelf, so an invoice for it
    // needs no warehouse and moves nothing. The default-warehouse rule is about
    // goods, and refusing here would make a service company unable to sell.
    $invoice = nxS8bInvoice([
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '2',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')
        ->assertOk()
        ->assertJsonPath('data.status', 'issued');

    expect(StockMovement::query()->count())->toBe(0)
        ->and(DB::table('journals')->count())->toBe(1);
});

it('refuses the whole issue when the sale cannot be funded', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    nxS8bDefaultWarehouse($this->companyId);

    $customerId = DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $this->receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = nxS8bInvoice([
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '100.0000',
        ]],
    ]);

    // The customer would take four and the shelf holds none.
    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'does not allow stock to go negative'));

    // The journal was built before the issue was attempted, so this is what
    // proves the two are one transaction: a sale that cannot be funded leaves
    // no entry and no movement, rather than a ledger that says the goods left
    // and a shelf that says they did not.
    expect(DB::table('journals')->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and(nxS8bLedger($this->inventoryAccountId)['debit'])->toBe('0.0000');

    $this->getJson('/api/v1/invoices/'.$invoice['ulid'])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft');
});

/*
|--------------------------------------------------------------------------
| The whole cycle
|--------------------------------------------------------------------------
*/

it('keeps every journal balanced from delivery through sale', function () {
    Sanctum::actingAs(nxS8bMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $warehouseId = nxS8bDefaultWarehouse($this->companyId);

    // Buy: goods arrive at 10.00 and are billed at 12.00, so there is a real
    // variance as well as a real clearing.
    $this->postJson('/api/v1/stock/receipts', nxS8bReceiptBody([
        'warehouse_id' => $warehouseId,
        'supplier_id' => $this->supplierId,
        'quantity' => '10',
        'unit_cost' => '10.0000',
    ]))->assertCreated();

    $bill = nxS8bApprovedBill(nxS8bBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->expenseAccountId,
            'quantity' => '10',
            'unit_price' => '12.0000',
        ]],
    ]));

    nxS8bPostBill($bill['ulid']);

    // Sell: four of the ten leave the shelf.
    $customerId = DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $this->receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = nxS8bInvoice([
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '100.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    // Four entries: the receipt, the bill, the invoice and the issue. The bill
    // is 120 against 100 of goods received and 20 of variance, the invoice 400
    // against revenue, and the issue 40 — four of the ten at what they cost,
    // which is the receipt's price and not the bill's.
    expect(DB::table('journals')->count())->toBe(4);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true)
        ->assertJsonPath('meta.total_debit', '660.0000')
        ->assertJsonPath('meta.total_credit', '660.0000');

    // Six of the ten are left, carried at what they cost: 60.
    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '60.0000')
        ->assertJsonPath('reconciled', true);

    expect(nxS8bLedger($this->inventoryAccountId)['closing'])->toBe('60.0000');
});
