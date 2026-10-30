<?php

use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Jobs\AlertLowStock;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Enums\OutboxStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAccountingFixtures;

/*
 * The low-stock alert is a queued job, so it is exercised by running its
 * handler directly rather than through a queue worker — the same way the
 * ledger-balance job is tested. What it decides is a database row, and that is
 * what these tests read back.
 */
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->cogsAccountId = $this->makeAccount('5100', 'Cost of Sales', 'expense', 'debit');

    $this->warehouseId = (int) Warehouse::create([
        'company_id' => $this->companyId,
        'code' => 'MAIN',
        'name' => 'Main Store',
        'status' => 'active',
        'is_default' => true,
    ])->getKey();
});

/**
 * An item the company watches, with the level it wants reordering at.
 *
 * The company is passed in rather than read off the test: the fixture trait
 * declares `companyId` protected, and these helpers are file-scope functions
 * with no access to it. The account ids are not declared by the trait at all,
 * so those are freely readable as dynamic properties — the same split the
 * stock tests already use.
 *
 * @param  array<string, mixed>  $overrides
 */
function nxLowStockProduct(int $companyId, array $overrides = []): int
{
    return (int) Product::create([
        'company_id' => $companyId,
        'sku' => 'SKU-'.Str::upper(Str::random(6)),
        'name' => 'Watched Widget',
        'unit' => 'pcs',
        'product_type' => 'goods',
        'inventory_account_id' => test()->inventoryAccountId,
        'expense_account_id' => test()->cogsAccountId,
        'reorder_level' => '10.000000',
        'status' => 'active',
        ...$overrides,
    ])->getKey();
}

/**
 * Open a FIFO layer, which is what the job reads the shelf from.
 */
function nxLowStockLayer(int $companyId, int $productId, string $quantity): void
{
    app(FifoLayerLedger::class)->open(
        $companyId,
        $productId,
        test()->warehouseId,
        null,
        '2026-03-15',
        $quantity,
        '5.0000',
    );
}

it('raises one alert for an item that has fallen to its reorder level', function () {
    $productId = nxLowStockProduct($this->companyId, ['sku' => 'LOW-1', 'reorder_level' => '10.000000']);
    nxLowStockLayer($this->companyId, $productId, '8.000000');

    (new AlertLowStock($this->companyId))->handle();

    $event = OutboxEvent::query()->sole();

    expect($event->event_type)->toBe('inventory.low_stock')
        ->and($event->aggregate_type)->toBe('product')
        ->and((int) $event->aggregate_id)->toBe($productId)
        ->and($event->status)->toBe(OutboxStatus::Pending)
        ->and((int) $event->company_id)->toBe($this->companyId)
        ->and($event->payload)->toMatchArray([
            'product_id' => $productId,
            'sku' => 'LOW-1',
            'on_hand' => '8.000000',
            'reorder_level' => '10.000000',
        ]);
});

it('treats an item sitting exactly at its level as low', function () {
    $productId = nxLowStockProduct($this->companyId, ['reorder_level' => '10.000000']);
    nxLowStockLayer($this->companyId, $productId, '10.000000');

    (new AlertLowStock($this->companyId))->handle();

    expect(OutboxEvent::query()->count())->toBe(1);
});

it('leaves an item alone while it is still above its level', function () {
    $productId = nxLowStockProduct($this->companyId, ['reorder_level' => '5.000000']);
    nxLowStockLayer($this->companyId, $productId, '8.000000');

    (new AlertLowStock($this->companyId))->handle();

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('counts the stock in every warehouse the item is kept in', function () {
    $secondWarehouseId = (int) Warehouse::create([
        'company_id' => $this->companyId,
        'code' => 'SECOND',
        'name' => 'Second Store',
        'status' => 'active',
        'is_default' => false,
    ])->getKey();

    $productId = nxLowStockProduct($this->companyId, ['reorder_level' => '10.000000']);

    // Six here and six there is twelve, which is above the level; neither
    // warehouse on its own would be, so reading one of them would be wrong.
    nxLowStockLayer($this->companyId, $productId, '6.000000');

    app(FifoLayerLedger::class)->open(
        $this->companyId,
        $productId,
        $secondWarehouseId,
        null,
        '2026-03-15',
        '6.000000',
        '5.0000',
    );

    (new AlertLowStock($this->companyId))->handle();

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('does not raise a second alert while one is still outstanding', function () {
    $productId = nxLowStockProduct($this->companyId);
    nxLowStockLayer($this->companyId, $productId, '1.000000');

    (new AlertLowStock($this->companyId))->handle();
    (new AlertLowStock($this->companyId))->handle();

    expect(OutboxEvent::query()->count())->toBe(1);
});

it('ignores a service, a retired item and one nobody is watching', function () {
    // A service holds no stock, so there is no shelf to be low.
    nxLowStockProduct($this->companyId, [
        'product_type' => 'service',
        'inventory_account_id' => null,
        'expense_account_id' => null,
    ]);

    // Retired: the item has been withdrawn and is no longer the job's concern.
    nxLowStockProduct($this->companyId, ['status' => 'inactive']);

    // No reorder level: nobody asked to be told about this one.
    nxLowStockProduct($this->companyId, ['reorder_level' => null]);

    (new AlertLowStock($this->companyId))->handle();

    expect(OutboxEvent::query()->count())->toBe(0);
});

it('raises nothing for a company that has no watched stock', function () {
    (new AlertLowStock($this->companyId))->handle();

    expect(OutboxEvent::query()->count())->toBe(0);
});
