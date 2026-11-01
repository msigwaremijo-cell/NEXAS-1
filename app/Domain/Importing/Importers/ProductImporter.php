<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Domain\Importing\Services\CellReader;
use App\Domain\Inventory\Enums\ProductType;
use App\Domain\Inventory\Enums\StockItemStatus;
use App\Models\Account;
use App\Models\Product;
use App\Models\TaxCode;

/**
 * The item catalogue, brought in as a file.
 *
 * The three account columns are not decoration: a receipt debits the product's
 * inventory account and an issue credits it, so a product is what decides where
 * its own stock sits in the books. The file names them by code, and they are
 * resolved against the company's own chart so an item can never be pointed at
 * another tenant's account.
 *
 * What the item is decides which accounts it needs. A goods item is required to
 * name both its inventory account and the expense account its cost goes to,
 * because without them it cannot be received or issued at all. A service names
 * neither and holds no stock, so any account columns a file fills in for one
 * are dropped rather than stored, which is what the item screen does too.
 */
final class ProductImporter extends AbstractDatasetImporter
{
    private const SKU_LIMIT = 40;

    private const NAME_LIMIT = 255;

    private const UNIT_LIMIT = 16;

    private const CODE_LIMIT = 30;

    /**
     * @return array<string, string>
     */
    public function fields(): array
    {
        return [
            'sku' => 'item code (SKU)',
            'name' => 'item name',
            'unit' => 'unit of measure',
            'product_type' => 'item type',
            'inventory_account_code' => 'inventory account code',
            'revenue_account_code' => 'revenue account code',
            'expense_account_code' => 'expense account code',
            'tax_code' => 'tax code',
            'unit_cost' => 'unit cost',
            'sale_price' => 'sale price',
            'status' => 'status',
        ];
    }

    /**
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return ['sku', 'name'];
    }

    public function prepare(ImportRequest $request, ImportTable $table): DatasetPlan
    {
        $existing = array_fill_keys(
            Product::withTrashed()
                ->where('company_id', $request->companyId)
                ->pluck('sku')
                ->all(),
            true
        );

        $accounts = $this->referenceAccounts($request->companyId);
        $taxCodes = $this->taxCodesByCode($request->companyId);
        $skuLines = $this->valueLines($request, $table, 'sku');

        [$rows, $errors] = $this->readRows(
            $table,
            function (array $row, int $line) use ($request, $existing, $accounts, $taxCodes, $skuLines): array {
                return $this->readRow($request, $row, $line, $existing, $accounts, $taxCodes, $skuLines);
            }
        );

        return new DatasetPlan($rows, $errors, ['products' => count($rows)]);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, true>  $existing
     * @param  array<string, Account>  $accounts
     * @param  array<string, int>  $taxCodes
     * @param  array<string, list<int>>  $skuLines
     * @return array<string, mixed>
     */
    private function readRow(
        ImportRequest $request,
        array $row,
        int $line,
        array $existing,
        array $accounts,
        array $taxCodes,
        array $skuLines,
    ): array {
        $sku = CellReader::text($this->cell($request, $row, 'sku'), 'sku', $this->label('sku'), $line, self::SKU_LIMIT, true);

        if ($repeat = $this->repeatOf($skuLines, $sku, $line)) {
            throw ImportRowRefusal::of(ImportRowError::duplicateInFile($line, 'sku', $this->label('sku'), $sku, $repeat));
        }

        if (isset($existing[$sku])) {
            throw ImportRowRefusal::of(ImportRowError::alreadyExists($line, 'sku', $this->label('sku'), $sku, 'product'));
        }

        $name = CellReader::text($this->cell($request, $row, 'name'), 'name', $this->label('name'), $line, self::NAME_LIMIT, true);

        $type = ProductType::from((string) CellReader::option(
            $this->cell($request, $row, 'product_type'),
            'product_type',
            $this->label('product_type'),
            $line,
            array_column(ProductType::cases(), 'value'),
            ProductType::Goods->value,
        ));

        $holdsStock = $type->holdsStock();

        $inventory = $this->account(
            $accounts,
            CellReader::text($this->cell($request, $row, 'inventory_account_code'), 'inventory_account_code', $this->label('inventory_account_code'), $line, self::CODE_LIMIT),
            'inventory_account_code',
            $this->label('inventory_account_code'),
            $line,
            $holdsStock,
        );

        $expense = $this->account(
            $accounts,
            CellReader::text($this->cell($request, $row, 'expense_account_code'), 'expense_account_code', $this->label('expense_account_code'), $line, self::CODE_LIMIT),
            'expense_account_code',
            $this->label('expense_account_code'),
            $line,
            $holdsStock,
        );

        $revenue = $this->account(
            $accounts,
            CellReader::text($this->cell($request, $row, 'revenue_account_code'), 'revenue_account_code', $this->label('revenue_account_code'), $line, self::CODE_LIMIT),
            'revenue_account_code',
            $this->label('revenue_account_code'),
            $line,
            false,
        );

        return [
            'sku' => $sku,
            'name' => $name,
            'unit' => CellReader::text($this->cell($request, $row, 'unit'), 'unit', $this->label('unit'), $line, self::UNIT_LIMIT) ?? 'pcs',
            'product_type' => $type->value,
            'inventory_account_id' => $holdsStock ? (int) $inventory?->getKey() : null,
            'expense_account_id' => $holdsStock ? (int) $expense?->getKey() : null,
            'revenue_account_id' => $revenue === null ? null : (int) $revenue->getKey(),
            'tax_code_id' => $this->taxCode($request, $row, $line, $taxCodes),
            'unit_cost' => CellReader::amount($this->cell($request, $row, 'unit_cost'), 'unit_cost', $this->label('unit_cost'), $line),
            'sale_price' => CellReader::amount($this->cell($request, $row, 'sale_price'), 'sale_price', $this->label('sale_price'), $line),
            'status' => CellReader::option(
                $this->cell($request, $row, 'status'),
                'status',
                $this->label('status'),
                $line,
                array_column(StockItemStatus::cases(), 'value'),
                StockItemStatus::Active->value,
            ),
        ];
    }

    /**
     * The tax code a row names, or null when it names none.
     *
     * @param  array<string, string>  $row
     * @param  array<string, int>  $taxCodes
     */
    private function taxCode(ImportRequest $request, array $row, int $line, array $taxCodes): ?int
    {
        $code = CellReader::text($this->cell($request, $row, 'tax_code'), 'tax_code', $this->label('tax_code'), $line, self::CODE_LIMIT);

        if ($code === null) {
            return null;
        }

        $id = $taxCodes[$code] ?? null;

        if ($id === null) {
            throw ImportRowRefusal::of(
                ImportRowError::unknownReference($line, 'tax_code', $this->label('tax_code'), $code, 'tax code')
            );
        }

        return $id;
    }

    /**
     * @return array<string, int>
     */
    private function taxCodesByCode(int $companyId): array
    {
        /** @var array<string, int> $codes */
        $codes = TaxCode::query()
            ->where('company_id', $companyId)
            ->pluck('id', 'code')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $codes;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function commit(ImportRequest $request, array $rows): array
    {
        $created = 0;

        foreach ($rows as $row) {
            Product::create([
                'company_id' => $request->companyId,
                'sku' => $row['sku'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'product_type' => $row['product_type'],
                'inventory_account_id' => $row['inventory_account_id'],
                'revenue_account_id' => $row['revenue_account_id'],
                'expense_account_id' => $row['expense_account_id'],
                'tax_code_id' => $row['tax_code_id'],
                'unit_cost' => $row['unit_cost'],
                'sale_price' => $row['sale_price'],
                'status' => $row['status'],
                'row_version' => 0,
            ]);

            $created++;
        }

        return ['products' => $created];
    }
}
