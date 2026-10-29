<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Inventory\Enums\InventoryCosting;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockLayer;
use App\Models\StockLayerConsumption;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Stock is carried in the ledger, so every assertion here goes through the real
// schema, its CHECK constraints and its triggers rather than around them. What
// a movement is worth is decided by the layers it drew on, and the only way to
// show that is to post movements and read the books back.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // Stock is carried at 1200 and the cost of what leaves lands on 5100; a
    // receipt is met by what the supplier is owed on 2100, and a count that
    // found less than the books say is written off to 5200. Keeping the
    // inventory account to itself is what makes the ledger's own balance a
    // figure the valuation report can be checked against.
    $this->inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->cogsAccountId = $this->makeAccount('5100', 'Cost of Sales', 'expense', 'debit');
    $this->counterAccountId = $this->makeAccount('2100', 'Goods Received Not Invoiced', 'liability', 'credit');
    $this->shrinkageAccountId = $this->makeAccount('5200', 'Stock Shrinkage', 'expense', 'debit');

    $this->productId = nxStockProduct($this->companyId);
    $this->warehouseId = nxStockWarehouse($this->companyId, 'MAIN', 'Main Store');
    // A second place to keep stock, because a transfer needs two of them.
    $this->secondWarehouseId = nxStockWarehouse($this->companyId, 'SECOND', 'Second Store');

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxStockMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * An item that holds stock, naming the two accounts its own movements post
 * through. Goods without both cannot be received or issued at all, which is why
 * the catalogue requires them when such an item is created.
 *
 * @param  array<string, mixed>  $overrides
 */
function nxStockProduct(int $companyId, array $overrides = []): int
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

function nxStockWarehouse(int $companyId, string $code, string $name): int
{
    return (int) Warehouse::create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'is_default' => false,
    ])->getKey();
}

/**
 * The choices a company has made about its stock, written where the policy
 * looks for them.
 *
 * @param  array<string, mixed>  $settings
 */
function nxStockSettings(int $companyId, array $settings): void
{
    Company::query()->whereKey($companyId)->firstOrFail()->update(['settings' => $settings]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxStockReceiptBody(array $overrides = []): array
{
    return [
        'product_id' => test()->productId,
        'warehouse_id' => test()->warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '50',
        'unit_cost' => '10.0000',
        'counter_account_id' => test()->counterAccountId,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxStockIssueBody(array $overrides = []): array
{
    return [
        'product_id' => test()->productId,
        'warehouse_id' => test()->warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '60',
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxStockAdjustmentBody(array $overrides = []): array
{
    return [
        'product_id' => test()->productId,
        'warehouse_id' => test()->warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '-5',
        'counter_account_id' => test()->shrinkageAccountId,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the stock surface without a token', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/products'],
    ['POST', '/api/v1/products'],
    ['POST', '/api/v1/warehouses'],
    ['GET', '/api/v1/stock/movements'],
    ['POST', '/api/v1/stock/receipts'],
    ['POST', '/api/v1/stock/transfers'],
    ['GET', '/api/v1/reports/stock-valuation'],
    ['GET', '/api/v1/company/inventory-settings'],
    ['PATCH', '/api/v1/company/inventory-settings'],
]);

it('lets an auditor read the stock and the valuation without moving any of it', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // What is on the shelf is a report-level fact, so reading it is not
    // withheld. Moving it is what is.
    $this->getJson('/api/v1/stock/movements')->assertOk();
    $this->getJson('/api/v1/reports/stock-valuation')->assertOk();

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody())->assertForbidden();

    expect(StockMovement::query()->count())->toBe(0);
});

it('lets a cashier look an item up without being able to move stock', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody())->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Costing
|--------------------------------------------------------------------------
*/

it('costs an issue from the oldest layers first, at each layer own price', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // 50 at 10.00, then 50 at 14.00.
    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '50', 'unit_cost' => '10.0000']))
        ->assertCreated()
        ->assertJsonPath('data.movement_type', 'receipt')
        ->assertJsonPath('data.quantity', '50.000000')
        ->assertJsonPath('data.total_cost', '500.0000');

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '50', 'unit_cost' => '14.0000']))
        ->assertCreated()
        ->assertJsonPath('data.total_cost', '700.0000');

    // 60 out: the whole of the first delivery, then 10 of the second. What it
    // costs is what those particular units cost, not an average of everything
    // on the shelf — 50 x 10 + 10 x 14 = 640.
    $issue = $this->postJson('/api/v1/stock/issues', nxStockIssueBody(['quantity' => '60']))
        ->assertCreated()
        ->assertJsonPath('data.quantity', '-60.000000')
        ->assertJsonPath('data.total_cost', '640.0000')
        ->assertJsonPath('data.unit_cost', '10.6667');

    // The draw is written down layer by layer, which is what makes the issue
    // reversible later without pricing it again.
    $consumptions = StockLayerConsumption::query()->orderBy('id')->get();

    expect($consumptions)->toHaveCount(2)
        ->and($consumptions[0]->quantity)->toBe('50.000000')
        ->and($consumptions[0]->unit_cost)->toBe('10.0000')
        ->and($consumptions[1]->quantity)->toBe('10.000000')
        ->and($consumptions[1]->unit_cost)->toBe('14.0000');

    expect(StockLayer::query()->orderBy('id')->pluck('quantity_remaining')->all())
        ->toBe(['0.000000', '40.000000']);

    // Dr the product's own cost of sales account, Cr its inventory account.
    $lines = DB::table('journal_lines')
        ->where('journal_id', (int) $issue->json('data.journal_id'))
        ->orderBy('line_no')
        ->get();

    expect($lines)->toHaveCount(2)
        ->and((int) $lines[0]->account_id)->toBe($this->cogsAccountId)
        ->and($lines[0]->debit)->toBe('640.0000')
        ->and($lines[0]->credit)->toBe('0.0000')
        ->and((int) $lines[1]->account_id)->toBe($this->inventoryAccountId)
        ->and($lines[1]->debit)->toBe('0.0000')
        ->and($lines[1]->credit)->toBe('640.0000');

    // 500 + 700 arrived in the inventory account and 640 left it.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->inventoryAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '1200.0000')
        ->assertJsonPath('meta.period_credit', '640.0000')
        ->assertJsonPath('meta.closing_balance', '560.0000');
});

it('answers a second issue from the layers the first one left behind', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '50', 'unit_cost' => '10.0000']))->assertCreated();
    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '50', 'unit_cost' => '14.0000']))->assertCreated();

    // Two issues of 30 asked for one after the other. The layers are read with
    // a locking read inside the issue's own transaction, so a second caller
    // reads the shelf the first left rather than the shelf as it was: 20 units
    // of the first delivery and 10 of the second. The same 30 units cannot be
    // handed out twice.
    $this->postJson('/api/v1/stock/issues', nxStockIssueBody(['quantity' => '30']))
        ->assertCreated()
        ->assertJsonPath('data.total_cost', '300.0000');

    $this->postJson('/api/v1/stock/issues', nxStockIssueBody(['quantity' => '30']))
        ->assertCreated()
        ->assertJsonPath('data.total_cost', '340.0000');

    $first = StockLayer::query()->orderBy('id')->firstOrFail();

    // The first delivery held exactly 50 and exactly 50 was drawn from it —
    // 30 by the first issue and 20 by the second, and not one unit more.
    expect($first->quantity_remaining)->toBe('0.000000')
        ->and((float) DB::table('stock_layer_consumptions')->where('layer_id', $first->getKey())->sum('quantity'))
        ->toBe(50.0);

    // And no layer anywhere was drawn on beyond what it received.
    $overConsumed = DB::table('stock_layers as l')
        ->join('stock_layer_consumptions as c', 'c.layer_id', '=', 'l.id')
        ->select('l.id')
        ->groupBy('l.id', 'l.quantity_received')
        ->havingRaw('SUM(c.quantity) > l.quantity_received')
        ->get();

    expect($overConsumed)->toHaveCount(0);
});

/*
|--------------------------------------------------------------------------
| The negative stock policy
|--------------------------------------------------------------------------
*/

it('refuses an issue that would take the stock below zero when the company forbids it', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Nothing has been received, and the company has not opted into negatives.
    $this->postJson('/api/v1/stock/issues', nxStockIssueBody(['quantity' => '5']))
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'does not allow stock to go negative'));

    // Refused as a whole: no movement, no entry, nothing drawn from anywhere.
    expect(StockMovement::query()->count())->toBe(0)
        ->and(StockLayerConsumption::query()->count())->toBe(0)
        ->and(DB::table('journals')->count())->toBe(0);
});

it('lets the stock go negative when the company allows it, and posts nothing for units it never bought', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    nxStockSettings($this->companyId, [
        'inventory_costing' => 'fifo',
        'allow_negative_stock' => true,
    ]);

    $this->postJson('/api/v1/stock/issues', nxStockIssueBody(['quantity' => '5']))
        ->assertCreated()
        ->assertJsonPath('data.quantity', '-5.000000')
        ->assertJsonPath('data.total_cost', '0.0000')
        ->assertJsonPath('data.journal_id', null);

    // Unfunded units were never bought, so there is no value to take out of the
    // inventory account. A journal of two zeroes would say nothing, and the
    // books are left alone.
    expect(DB::table('journals')->count())->toBe(0)
        ->and(StockLayerConsumption::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The costing method
|--------------------------------------------------------------------------
*/

it('reports the inventory policy the engine will actually apply', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // Read back through the engine rather than echoed from the settings blob,
    // so what the screen is shown is what a receipt will do.
    $this->getJson('/api/v1/company/inventory-settings')
        ->assertOk()
        ->assertJsonPath('data.company_id', $this->companyId)
        ->assertJsonPath('data.inventory_costing', 'fifo')
        ->assertJsonPath('data.costing_implemented', true)
        ->assertJsonPath('data.costing_locked', false)
        ->assertJsonPath('data.allow_negative_stock', false);
});

it('lets the costing method change while the shelves are empty', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->patchJson('/api/v1/company/inventory-settings', ['inventory_costing' => 'weighted_average'])
        ->assertOk()
        ->assertJsonPath('data.inventory_costing', 'weighted_average')
        ->assertJsonPath('data.costing_implemented', false);

    // And back again, because a choice made while the shelves are empty is not
    // binding until some stock has actually moved.
    $this->patchJson('/api/v1/company/inventory-settings', ['inventory_costing' => InventoryCosting::Fifo->value])
        ->assertOk()
        ->assertJsonPath('data.inventory_costing', 'fifo');
});

it('refuses a change of costing method once any stock has moved', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody())->assertCreated();

    // The layer that receipt opened was priced by FIFO. Valuing what is left of
    // it by another rule would put the difference nowhere in particular, so the
    // settings route refuses rather than writing a method the engine would then
    // apply to layers it never priced.
    $this->patchJson('/api/v1/company/inventory-settings', ['inventory_costing' => InventoryCosting::WeightedAverage->value])
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException');

    // The refusal has to leave the setting alone, not merely answer 409.
    $this->getJson('/api/v1/company/inventory-settings')
        ->assertOk()
        ->assertJsonPath('data.inventory_costing', 'fifo')
        ->assertJsonPath('data.costing_locked', true);
});

it('answers a change to the method already in force without refusing it', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody())->assertCreated();

    // The lock stops the method from changing, not the setting from being
    // written: restating what is already in force changes nothing, so it is not
    // the change the guard exists to refuse.
    $this->patchJson('/api/v1/company/inventory-settings', ['inventory_costing' => InventoryCosting::Fifo->value])
        ->assertOk()
        ->assertJsonPath('data.inventory_costing', 'fifo')
        ->assertJsonPath('data.costing_locked', true);
});

it('writes one policy setting without dropping the other', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    nxStockSettings($this->companyId, ['inventory_costing' => 'fifo', 'allow_negative_stock' => false]);

    $this->patchJson('/api/v1/company/inventory-settings', ['allow_negative_stock' => true])
        ->assertOk()
        ->assertJsonPath('data.allow_negative_stock', true)
        ->assertJsonPath('data.inventory_costing', 'fifo');

    // The blob is merged rather than replaced, so the key the request never
    // mentioned is still there.
    expect(Company::query()->findOrFail($this->companyId)->settings)
        ->toMatchArray(['inventory_costing' => 'fifo', 'allow_negative_stock' => true]);
});

it('refuses a costing method the system does not know', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->patchJson('/api/v1/company/inventory-settings', ['inventory_costing' => 'lifo'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('inventory_costing');
});

it('does not let a member without the right read or change the policy', function (string $role) {
    Sanctum::actingAs(nxStockMember($this->companyId, $role));

    $this->getJson('/api/v1/company/inventory-settings')->assertForbidden();

    $this->patchJson('/api/v1/company/inventory-settings', ['inventory_costing' => InventoryCosting::WeightedAverage->value])
        ->assertForbidden();

    // The refusal has to leave the method alone, not merely answer 403.
    expect(app(StockPolicy::class)->costing(Company::query()->findOrFail($this->companyId))->value)
        ->toBe(InventoryCosting::Fifo->value);
})->with([AccessMatrix::ROLE_CASHIER, AccessMatrix::ROLE_AUDITOR]);

it('refuses to move stock for a company costed by a method that has not been built', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Weighted average is named in the enum so that a company asking for it is
    // refused out loud rather than quietly answered with FIFO numbers.
    nxStockSettings($this->companyId, ['inventory_costing' => 'weighted_average']);

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody())
        ->assertStatus(422)
        ->assertJsonPath('error', 'UnsupportedCostingMethodException');

    expect(StockMovement::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| What the stock is worth
|--------------------------------------------------------------------------
*/

it('values what is left from its layers, and agrees with the ledger', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '50', 'unit_cost' => '10.0000']))->assertCreated();
    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '50', 'unit_cost' => '14.0000']))->assertCreated();
    $this->postJson('/api/v1/stock/issues', nxStockIssueBody(['quantity' => '60']))->assertCreated();

    // A count that found less than the books say. A shortfall is stock leaving,
    // so it is priced from the layers like any issue: 5 x 14 = 70.
    $this->postJson('/api/v1/stock/adjustments', nxStockAdjustmentBody())
        ->assertCreated()
        ->assertJsonPath('data.movement_type', 'adjustment')
        ->assertJsonPath('data.quantity', '-5.000000')
        ->assertJsonPath('data.total_cost', '70.0000');

    // A transfer carries the stock, and its cost, to the second warehouse. It
    // writes two movements and no entry: both sides of it belong to the same
    // inventory account, so the company is neither richer nor poorer for having
    // moved its own goods.
    $this->postJson('/api/v1/stock/transfers', [
        'product_id' => $this->productId,
        'from_warehouse_id' => $this->warehouseId,
        'to_warehouse_id' => $this->secondWarehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '10',
    ])
        ->assertCreated()
        ->assertJsonPath('data.out.movement_type', 'transfer_out')
        ->assertJsonPath('data.out.quantity', '-10.000000')
        ->assertJsonPath('data.out.total_cost', '140.0000')
        ->assertJsonPath('data.out.journal_id', null)
        ->assertJsonPath('data.in.movement_type', 'transfer_in')
        ->assertJsonPath('data.in.quantity', '10.000000')
        ->assertJsonPath('data.in.journal_id', null);

    // Two receipts, one issue and one write-off. The transfer is not one of
    // them, because it changed no account.
    expect(DB::table('journals')->count())->toBe(4);

    // The three ways of counting the shelf agree: what the movements say is on
    // hand, what the layers say is left, and what was received less what was
    // taken from it.
    $onHand = (float) DB::table('stock_movements')->sum('quantity');
    $remaining = (float) StockLayer::query()->sum('quantity_remaining');
    $received = (float) StockLayer::query()->sum('quantity_received');
    $consumed = (float) StockLayerConsumption::query()->sum('quantity');

    expect($onHand)->toBe(35.0)
        ->and($remaining)->toBe(35.0)
        ->and($received - $consumed)->toBe(35.0);

    // What is left is all of it from the 14.00 delivery: 25 in the main store
    // and 10 in the second, worth 490 in total.
    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '490.0000')
        ->assertJsonPath('reconciled', true)
        ->assertJsonPath('lines.0.product_sku', Product::query()->whereKey($this->productId)->value('sku'))
        ->assertJsonPath('lines.0.warehouse_code', 'MAIN')
        ->assertJsonPath('lines.0.unit_cost', '14.0000')
        ->assertJsonPath('lines.0.value', '350.0000')
        ->assertJsonPath('lines.1.warehouse_code', 'SECOND')
        ->assertJsonPath('lines.1.value', '140.0000');

    // The ledger's own copy of the same figure, which is what `reconciled`
    // above is comparing against.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->inventoryAccountId)
        ->assertOk()
        ->assertJsonPath('meta.closing_balance', '490.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

/*
|--------------------------------------------------------------------------
| Correction
|--------------------------------------------------------------------------
*/

it('corrects a movement by reversing it, and refuses to reverse it twice', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $receipt = $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '10', 'unit_cost' => '5.0000']))
        ->assertCreated()
        ->assertJsonPath('data.total_cost', '50.0000');

    $ulid = $receipt->json('data.ulid');
    $id = (int) $receipt->json('data.id');

    $this->postJson('/api/v1/stock/movements/'.$ulid.'/reversal')
        ->assertCreated()
        ->assertJsonPath('data.movement_type', 'issue')
        ->assertJsonPath('data.quantity', '-10.000000')
        ->assertJsonPath('data.total_cost', '50.0000')
        ->assertJsonPath('data.reverses_movement_id', $id);

    // The movement it answers is not edited. It is marked reversed, and the
    // layer it opened is drawn back down to nothing.
    expect(DB::table('stock_movements')->where('id', $id)->value('status'))->toBe('reversed')
        ->and((float) StockLayer::query()->sum('quantity_remaining'))->toBe(0.0);

    // Two entries: the receipt, and the mirror image of its own lines.
    expect(DB::table('journals')->orderBy('id')->pluck('journal_type')->all())
        ->toBe(['stock', 'reversal']);

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->inventoryAccountId)
        ->assertOk()
        ->assertJsonPath('meta.closing_balance', '0.0000');

    // Nothing is left of it to undo a second time.
    $this->postJson('/api/v1/stock/movements/'.$ulid.'/reversal')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Only a posted stock movement can be reversed'));
});

it('refuses to reverse one half of a transfer', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '20', 'unit_cost' => '5.0000']))->assertCreated();

    $out = $this->postJson('/api/v1/stock/transfers', [
        'product_id' => $this->productId,
        'from_warehouse_id' => $this->warehouseId,
        'to_warehouse_id' => $this->secondWarehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '5',
    ])->assertCreated();

    // Undoing one half would leave the same stock counted in both warehouses at
    // once, so a transfer is corrected by transferring it back.
    $this->postJson('/api/v1/stock/movements/'.$out->json('data.out.ulid').'/reversal')
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'transferring the stock back'));
});

it('refuses to edit or delete a posted movement, or to rewrite a layer', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['quantity' => '10', 'unit_cost' => '5.0000']))->assertCreated();

    $movementId = (int) StockMovement::query()->sole()->getKey();
    $layerId = (int) StockLayer::query()->sole()->getKey();

    // What a movement did to the stock is a fact. Correcting it is an opposite
    // movement, not an edit that leaves no trace.
    expect(fn () => DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => '99']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('stock_movements')->where('id', $movementId)->delete())
        ->toThrow(QueryException::class);

    // A layer's price cannot be restated after the fact either, or every issue
    // priced from it would change retroactively.
    expect(fn () => DB::table('stock_layers')->where('id', $layerId)->update(['quantity_received' => '99']))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Master data
|--------------------------------------------------------------------------
*/

it('adds a goods item that names the accounts its own stock posts through', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/products', [
        'sku' => 'WIDGET-1',
        'name' => 'Blue Widget',
        'unit' => 'pcs',
        'product_type' => 'goods',
        'inventory_account_id' => $this->inventoryAccountId,
        'expense_account_id' => $this->cogsAccountId,
    ])
        ->assertCreated()
        ->assertJsonPath('data.sku', 'WIDGET-1')
        ->assertJsonPath('data.holds_stock', true)
        ->assertJsonPath('data.inventory_account_id', $this->inventoryAccountId)
        ->assertJsonPath('data.expense_account_id', $this->cogsAccountId);
});

it('refuses a goods item that names no inventory account', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Without it the item has nowhere to carry its stock, so it could never be
    // received or issued at all.
    $this->postJson('/api/v1/products', [
        'sku' => 'WIDGET-2',
        'name' => 'Blue Widget',
        'product_type' => 'goods',
        'expense_account_id' => $this->cogsAccountId,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('inventory_account_id');
});

it('refuses a stock movement against an item held as a service', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $serviceId = nxStockProduct($this->companyId, [
        'sku' => 'SERVICE-1',
        'name' => 'Installation',
        'product_type' => 'service',
        'inventory_account_id' => null,
        'expense_account_id' => null,
    ]);

    $this->postJson('/api/v1/stock/receipts', nxStockReceiptBody(['product_id' => $serviceId]))
        ->assertStatus(409)
        ->assertJsonPath('error', 'StockStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'holds no stock'));

    expect(StockMovement::query()->count())->toBe(0);
});

it('keeps exactly one of the company warehouses as the default', function () {
    Sanctum::actingAs(nxStockMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/warehouses', ['code' => 'DAR', 'name' => 'Dar Store', 'is_default' => true])
        ->assertCreated()
        ->assertJsonPath('data.code', 'DAR')
        ->assertJsonPath('data.is_default', true);

    // Naming a second default demotes the first rather than leaving two
    // candidates for a screen to choose between.
    $this->postJson('/api/v1/warehouses', ['code' => 'MWANZA', 'name' => 'Mwanza Store', 'is_default' => true])
        ->assertCreated();

    expect(Warehouse::query()->where('company_id', $this->companyId)->where('is_default', true)->count())->toBe(1)
        ->and(Warehouse::query()->where('code', 'DAR')->value('is_default'))->toBeFalsy();
});
