<?php

namespace App\Domain\Reporting\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * What the stock on hand is worth, and whether the books say the same.
 *
 * The value of one item in one place is the sum of its layers less what has
 * been drawn from them, and both halves of that are priced from the layers
 * themselves: a layer holds what it received and what that cost, and every
 * consumption holds what left it and at which price. So the report asks the
 * layers rather than recomputing a cost from the movements, which is the same
 * reason `stock_layer_consumptions` exists at all.
 *
 * The date matters to both halves. A layer counts from the day it was received
 * and a consumption from the day the stock left, so "what was the stock worth
 * on the last day of March" is answered by taking only what had arrived by then
 * and only what had gone by then. `consumed_on` is a movement date rather than
 * the clock, which is what makes a past valuation reproducible.
 *
 * The second half of the report is the reconciliation. Stock is carried in the
 * inventory account the product names, and the two figures should agree: every
 * movement posts exactly the value the layers moved. They can disagree for a
 * real reason — a manual journal to the inventory account, or a product whose
 * inventory account was changed after stock had already moved — and the
 * difference is reported per account rather than swallowed, because a valuation
 * that quietly disagrees with the ledger is worse than one that says so.
 */
final class StockValuationQuery
{
    public function __construct(
        private readonly LedgerQuery $ledger,
    ) {}

    /**
     * @return array{
     *     as_of: string,
     *     lines: list<array<string, mixed>>,
     *     total_value: string,
     *     control: list<array<string, mixed>>,
     *     reconciled: bool
     * }
     */
    public function valuation(int $companyId, ?string $asOf = null): array
    {
        $asOf = $asOf ?? now()->toDateString();

        $lines = $this->lines($companyId, $asOf);

        $total = Money::sum(array_map(
            static fn (array $line): Money => $line['value'],
            $lines,
        ));

        $control = $this->control($companyId, $asOf, $lines);

        return [
            'as_of' => $asOf,
            'lines' => array_map(static fn (array $line): array => [
                ...$line,
                'unit_cost' => $line['unit_cost']->toDecimal(),
                'value' => $line['value']->toDecimal(),
            ], $lines),
            'total_value' => $total->toDecimal(),
            'control' => $control,
            'reconciled' => $this->isReconciled($control),
        ];
    }

    /**
     * One row per item per place, with what is there and what it is worth.
     *
     * Rows that are empty at the date are dropped: what received and issued
     * everything it ever had is not stock on hand, and `quantity_remaining` on
     * the layers is not what the report asks — a layer can be empty while its
     * movements still belong to the period.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $companyId, string $asOf): array
    {
        $received = DB::table('stock_layers')
            ->selectRaw('product_id, warehouse_id, SUM(quantity_received) AS quantity, SUM(quantity_received * unit_cost) AS value')
            ->where('company_id', $companyId)
            ->where('received_on', '<=', $asOf)
            ->groupBy('product_id', 'warehouse_id');

        $consumed = DB::table('stock_layer_consumptions as c')
            ->join('stock_layers as l', 'l.id', '=', 'c.layer_id')
            ->selectRaw('l.product_id AS product_id, l.warehouse_id AS warehouse_id, SUM(c.quantity) AS quantity, SUM(c.total_cost) AS value')
            ->where('c.company_id', $companyId)
            ->where('c.consumed_on', '<=', $asOf)
            ->groupBy('l.product_id', 'l.warehouse_id');

        $rows = DB::query()
            ->fromSub($received, 'r')
            ->leftJoinSub($consumed, 'c', fn ($join) => $join
                ->on('c.product_id', '=', 'r.product_id')
                ->on('c.warehouse_id', '=', 'r.warehouse_id'))
            ->selectRaw(
                'r.product_id AS product_id, r.warehouse_id AS warehouse_id,'
                .' r.quantity - COALESCE(c.quantity, 0) AS quantity,'
                .' r.value - COALESCE(c.value, 0) AS value'
            )
            ->get();

        $products = $this->products($companyId, $rows->pluck('product_id')->all());
        $warehouses = $this->warehouses($companyId, $rows->pluck('warehouse_id')->all());

        $lines = [];

        foreach ($rows as $row) {
            $quantity = Quantity::of((string) $row->quantity);

            if (Quantity::isZero($quantity)) {
                continue;
            }

            $product = $products[(int) $row->product_id] ?? null;

            if ($product === null) {
                continue;
            }

            $value = Money::of((string) $row->value);
            $warehouse = $warehouses[(int) $row->warehouse_id] ?? null;

            $lines[] = [
                'product_id' => (int) $row->product_id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'inventory_account_id' => $product->inventory_account_id === null
                    ? null
                    : (int) $product->inventory_account_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_code' => $warehouse?->code,
                'warehouse_name' => $warehouse?->name,
                'quantity' => $quantity,
                // What one unit is carrying, taken from the whole of what is
                // left rather than from the price of the newest layer: two
                // deliveries at two prices leave one blended figure on the
                // shelf, and this is that figure.
                'unit_cost' => $value->dividedBy($quantity),
                'value' => $value,
            ];
        }

        usort($lines, static fn (array $left, array $right): int => [
            $left['product_sku'], $left['warehouse_code'],
        ] <=> [
            $right['product_sku'], $right['warehouse_code'],
        ]);

        return $lines;
    }

    /**
     * The stock value per inventory account beside the ledger's own figure for
     * it, so the two can be compared rather than assumed to agree.
     *
     * The accounts are taken from the catalogue rather than from the lines, so
     * an account whose stock has all been issued is still reported — with a
     * stock value of nothing and whatever the ledger holds, which is exactly
     * the case a reconciliation is for.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function control(int $companyId, string $asOf, array $lines): array
    {
        $holding = Product::query()
            ->where('company_id', $companyId)
            ->whereNotNull('inventory_account_id')
            ->get(['inventory_account_id']);

        /** @var array<int, Money> $byAccount */
        $byAccount = [];

        foreach ($holding as $product) {
            $byAccount[(int) $product->inventory_account_id] ??= Money::zero();
        }

        foreach ($lines as $line) {
            $accountId = $line['inventory_account_id'];

            if ($accountId === null) {
                continue;
            }

            $byAccount[$accountId] = ($byAccount[$accountId] ?? Money::zero())->plus($line['value']);
        }

        if ($byAccount === []) {
            return [];
        }

        $accountIds = array_keys($byAccount);

        $accounts = DB::table('accounts')
            ->where('company_id', $companyId)
            ->whereIn('id', $accountIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $control = [];

        foreach ($byAccount as $accountId => $stockValue) {
            $account = $accounts[$accountId] ?? null;

            // One query per account on purpose: this is a reconciliation
            // between two figures that have to be read from two places, and a
            // company keeps a handful of inventory accounts at most.
            $ledgerBalance = $this->ledger->accountBalance($companyId, $accountId, $asOf);

            $control[] = [
                'account_id' => $accountId,
                'account_code' => $account?->code,
                'account_name' => $account?->name,
                'stock_value' => $stockValue->toDecimal(),
                'ledger_balance' => $ledgerBalance->toDecimal(),
                'difference' => $ledgerBalance->minus($stockValue)->toDecimal(),
            ];
        }

        usort($control, static fn (array $left, array $right): int => $left['account_code'] <=> $right['account_code']);

        return $control;
    }

    /**
     * @param  list<array<string, mixed>>  $control
     */
    private function isReconciled(array $control): bool
    {
        foreach ($control as $account) {
            if (! Money::of($account['difference'])->isZero()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, Product>
     */
    private function products(int $companyId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy(static fn (Product $product): int => (int) $product->getKey())
            ->all();
    }

    /**
     * @param  array<int, int|string>  $warehouseIds
     * @return array<int, Warehouse>
     */
    private function warehouses(int $companyId, array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        return Warehouse::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $warehouseIds)
            ->get()
            ->keyBy(static fn (Warehouse $warehouse): int => (int) $warehouse->getKey())
            ->all();
    }
}
