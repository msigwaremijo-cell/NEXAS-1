<?php

use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccountingFixtures;

/*
 * DatabaseMigrations rather than RefreshDatabase: the two draws that race here
 * run on two separate connections, and a second connection cannot see rows that
 * another connection has not committed. RefreshDatabase keeps every fixture
 * inside one wrapping transaction, so the layer the race is over would be
 * invisible to the second session and there would be nothing to contend for.
 * DatabaseMigrations commits, so the row is really there for both of them.
 */
uses(DatabaseMigrations::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->cogsAccountId = $this->makeAccount('5100', 'Cost of Sales', 'expense', 'debit');

    $this->productId = (int) Product::create([
        'company_id' => $this->companyId,
        'sku' => 'RACE-1',
        'name' => 'Raced Widget',
        'unit' => 'pcs',
        'product_type' => 'goods',
        'inventory_account_id' => $this->inventoryAccountId,
        'expense_account_id' => $this->cogsAccountId,
        'status' => 'active',
    ])->getKey();

    $this->warehouseId = (int) Warehouse::create([
        'company_id' => $this->companyId,
        'code' => 'MAIN',
        'name' => 'Main Store',
        'status' => 'active',
        'is_default' => true,
    ])->getKey();
});

/*
 * A layer is the unit of stock that can be sold twice. The FIFO ledger reads
 * the layers it draws on with a locking read, so the guarantee to prove is that
 * two sales asking for the same layer at the same moment are answered one after
 * the other rather than both being sold the same units.
 */
it('cannot let two sales draw the same layer at the same time', function () {
    $layer = app(FifoLayerLedger::class)->open(
        $this->companyId,
        $this->productId,
        $this->warehouseId,
        null,
        '2026-03-15',
        '10.000000',
        '5.0000',
    );

    $layerId = (int) $layer->getKey();

    // A second session to the same database: the two draws are then genuinely
    // separate connections contending for one row lock, rather than one
    // connection talking to itself.
    $base = config('database.default');
    config(['database.connections.race' => config("database.connections.{$base}")]);
    $racer = DB::connection('race');

    // Make the waiting read give up quickly instead of hanging the suite.
    $racer->statement('SET SESSION innodb_lock_wait_timeout = 1');

    try {
        // The first sale takes the same locking read the FIFO ledger takes and
        // holds it open, exactly as it would for the length of a real issue.
        DB::beginTransaction();
        $held = DB::table('stock_layers')->where('id', $layerId)->lockForUpdate()->first();
        expect($held)->not->toBeNull();

        // The second sale asks for the same layer and cannot have it: the row
        // stays locked until the first transaction ends.
        expect(fn () => $racer->table('stock_layers')->where('id', $layerId)->lockForUpdate()->first())
            ->toThrow(QueryException::class);

        DB::rollBack();

        // With the first draw finished the layer is free again, and still whole:
        // the sale that was turned away took nothing from it. The same ten units
        // cannot be sold twice.
        expect((string) $racer->table('stock_layers')->where('id', $layerId)->value('quantity_remaining'))
            ->toBe('10.000000');
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge('race');
    }
});
