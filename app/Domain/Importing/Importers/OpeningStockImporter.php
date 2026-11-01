<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportingException;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Domain\Importing\Services\CellReader;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Quantity;

/**
 * What is on the shelves on the day the books begin.
 *
 * Each row lays down one FIFO layer, dated the day the count was taken and
 * priced at the cost that was counted. That is exactly what a receipt would
 * have laid down, so from the first day the stock is costed the same way as
 * every receipt that follows — oldest layer first, at the price it arrived at.
 * The layer names no movement, because there was no movement: this is where
 * the stock already was. `receipt_movement_id` is nullable for that reason.
 *
 * Only goods hold stock, so a row naming a service is refused rather than
 * imported as a layer nothing could ever draw on. A product and a warehouse
 * may appear together only once: two rows for the same pair would be two
 * deliveries, which is not what an opening count is, and a silent second layer
 * is the kind of mistake that surfaces later as a stock value the ledger
 * disagrees with.
 *
 * This is the first half of the opening pair. It writes the layers, and the
 * opening balances that follow are refused unless the ledger they open agrees
 * with what these layers are worth — so the stock has to be laid down first.
 */
final class OpeningStockImporter extends AbstractDatasetImporter
{
    private const SKU_LIMIT = 40;

    private const WAREHOUSE_LIMIT = 20;

    public function __construct(
        private readonly FifoLayerLedger $layers,
    ) {}

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'product_sku' => 'item code (SKU)',
            'warehouse_code' => 'warehouse code',
            'quantity' => 'quantity on hand',
            'unit_cost' => 'unit cost',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return ['product_sku', 'warehouse_code', 'quantity', 'unit_cost'];
    }

    public function prepare(ImportRequest $request, ImportTable $table): DatasetPlan
    {
        if ($request->asOf === null) {
            throw ImportingException::missingAsOf($request->dataset->label());
        }

        $products = Product::query()
            ->where('company_id', $request->companyId)
            ->get()
            ->keyBy('sku')
            ->all();

        $warehouses = Warehouse::query()
            ->where('company_id', $request->companyId)
            ->get()
            ->keyBy('code')
            ->all();

        /** @var array<string, int> $seen  the pair already laid down, and its line */
        $seen = [];

        [$rows, $errors] = $this->readRows(
            $table,
            function (array $row, int $line) use ($request, $products, $warehouses, &$seen): array {
                return $this->readRow($request, $row, $line, $products, $warehouses, $seen);
            }
        );

        return new DatasetPlan($rows, $errors, ['layers' => count($rows)]);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, Product>  $products
     * @param  array<string, Warehouse>  $warehouses
     * @param  array<string, int>  $seen
     * @return array<string, mixed>
     */
    private function readRow(
        ImportRequest $request,
        array $row,
        int $line,
        array $products,
        array $warehouses,
        array &$seen,
    ): array {
        $sku = CellReader::text($this->cell($request, $row, 'product_sku'), 'product_sku', $this->label('product_sku'), $line, self::SKU_LIMIT, true);

        $product = $products[$sku] ?? null;

        // A service has nothing to count, so it is refused in the same breath
        // as an item that is not in the catalogue at all.
        if ($product === null || ! $product->holdsStock()) {
            throw ImportRowRefusal::of(
                ImportRowError::unknownReference($line, 'product_sku', $this->label('product_sku'), $sku, 'item that holds stock')
            );
        }

        $warehouseCode = CellReader::text($this->cell($request, $row, 'warehouse_code'), 'warehouse_code', $this->label('warehouse_code'), $line, self::WAREHOUSE_LIMIT, true);

        $warehouse = $warehouses[$warehouseCode] ?? null;

        if ($warehouse === null) {
            throw ImportRowRefusal::of(
                ImportRowError::unknownReference($line, 'warehouse_code', $this->label('warehouse_code'), $warehouseCode, 'warehouse')
            );
        }

        $pair = $sku.' in '.$warehouseCode;

        if (isset($seen[$pair])) {
            throw ImportRowRefusal::of(
                ImportRowError::duplicateInFile($line, 'product_sku', 'item code and warehouse', $pair, [$seen[$pair], $line])
            );
        }

        $seen[$pair] = $line;

        $quantity = CellReader::quantity($this->cell($request, $row, 'quantity'), 'quantity', $this->label('quantity'), $line, true);

        if (Quantity::isZero($quantity)) {
            throw ImportRowRefusal::of(ImportRowError::zeroQuantity($line, 'quantity', $this->label('quantity')));
        }

        return [
            'product_id' => (int) $product->getKey(),
            'warehouse_id' => (int) $warehouse->getKey(),
            'product_sku' => $sku,
            'warehouse_code' => $warehouseCode,
            'quantity' => $quantity,
            'unit_cost' => CellReader::amount($this->cell($request, $row, 'unit_cost'), 'unit_cost', $this->label('unit_cost'), $line, true),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commit(ImportRequest $request, array $rows): array
    {
        $value = Money::zero();

        foreach ($rows as $row) {
            $this->layers->open(
                $request->companyId,
                $row['product_id'],
                $row['warehouse_id'],
                null,
                (string) $request->asOf,
                $row['quantity'],
                $row['unit_cost'],
            );

            $value = $value->plus(Money::of($row['unit_cost'])->times($row['quantity']));
        }

        return ['layers' => count($rows), 'value' => $value->toDecimal()];
    }
}
