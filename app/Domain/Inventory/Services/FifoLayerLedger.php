<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LayerDraw;
use App\Domain\Inventory\DTOs\LayerDrawPlan;
use App\Models\StockLayer;
use App\Models\StockLayerConsumption;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * The layers stock is costed in, and the one place they are drawn on.
 *
 * A layer is a receipt of stock at a price. Drawing on the stock means taking
 * the oldest layers first and pricing what leaves at each layer's own cost, so
 * an issue that is made up of two deliveries is worth two different prices and
 * the total is the sum of them.
 *
 * Two things about the order of work matter.
 *
 * The layers are read with a locking read, and the plan is worked out from
 * that read inside the caller's transaction. Two issues asked for at the same
 * moment are therefore answered one after the other: the second reads the
 * layers as the first left them, and cannot be given the same units twice.
 *
 * The plan is written down before it is applied. `stock_layer_consumptions` is
 * append-only, so what an issue drew on and at what price is a record rather
 * than a recalculation — which is what makes reversing an issue a matter of
 * reading it back instead of pricing it again against a shelf that has moved
 * on since.
 *
 * Neither method opens a transaction of its own. They are only ever called
 * from inside one, and a plan taken outside the transaction that applies it
 * would be a plan about a picture that may already have changed.
 */
final class FifoLayerLedger
{
    /**
     * A layer's own records: what arrived, at what price, and how much of it
     * is left. `quantity_received` and `unit_cost` are frozen by a trigger
     * from this point on; only what remains of it moves.
     */
    public function open(
        int $companyId,
        int $productId,
        int $warehouseId,
        ?int $receiptMovementId,
        string $receivedOn,
        string $quantity,
        string $unitCost,
    ): StockLayer {
        $quantity = Quantity::of($quantity);

        return StockLayer::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'receipt_movement_id' => $receiptMovementId,
            'received_on' => $receivedOn,
            'quantity_received' => $quantity,
            'quantity_remaining' => $quantity,
            'unit_cost' => Money::of($unitCost)->toDecimal(),
            'row_version' => 0,
        ]);
    }

    /**
     * Work out which layers would fund a draw, oldest first.
     *
     * The layers this read touches stay locked until the transaction around it
     * ends, so the plan cannot be overtaken while the movement it belongs to
     * is being written.
     *
     * `preferLayerIds` moves named layers to the front of the queue without
     * breaking the order among the rest. It exists for reversing a receipt:
     * the stock the reversal has to take back out is the stock that receipt
     * brought in, not whatever happens to be oldest.
     *
     * @param  string  $quantity  How much is wanted, positive.
     * @param  array<int, int>  $preferLayerIds
     */
    public function plan(
        int $companyId,
        int $productId,
        int $warehouseId,
        string $quantity,
        array $preferLayerIds = [],
    ): LayerDrawPlan {
        $wanted = Quantity::of($quantity);

        $query = StockLayer::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_remaining', '>', 0);

        if ($preferLayerIds !== []) {
            $preferred = array_values(array_map(static fn (int|string $id): int => (int) $id, $preferLayerIds));
            $placeholders = implode(', ', array_fill(0, count($preferred), '?'));

            $query->orderByRaw("CASE WHEN `id` IN ({$placeholders}) THEN 0 ELSE 1 END", $preferred);
        }

        /** @var Collection<int, StockLayer> $layers */
        $layers = $query
            ->orderBy('received_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $draws = [];
        $outstanding = $wanted;

        foreach ($layers as $layer) {
            if (! Quantity::isPositive($outstanding)) {
                break;
            }

            $take = Quantity::lesser((string) $layer->quantity_remaining, $outstanding);

            if (! Quantity::isPositive($take)) {
                continue;
            }

            $draws[] = LayerDraw::of((int) $layer->getKey(), $take, (string) $layer->unit_cost);

            $outstanding = Quantity::subtract($outstanding, $take);
        }

        return new LayerDrawPlan($wanted, $draws, $outstanding);
    }

    /**
     * Write the plan down: one record of the draw per layer, then what is left
     * of each of them.
     *
     * The row is re-read with the lock already held so that what remains is
     * decremented from the figure the plan was worked out against, rather than
     * from a copy that may have gone stale.
     */
    public function apply(int $companyId, StockMovement $movement, LayerDrawPlan $plan, string $consumedOn): void
    {
        foreach ($plan->draws as $draw) {
            $layer = StockLayer::query()
                ->whereKey($draw->layerId)
                ->lockForUpdate()
                ->firstOrFail();

            StockLayerConsumption::create([
                'company_id' => $companyId,
                'movement_id' => (int) $movement->getKey(),
                'layer_id' => $draw->layerId,
                'quantity' => $draw->quantity,
                'consumed_on' => $consumedOn,
                'unit_cost' => $draw->unitCost,
                'total_cost' => $draw->totalCost->toDecimal(),
            ]);

            // Only what is left may move: the trigger freezes the rest of the
            // row, so this is the one column a draw is allowed to write, and
            // it only ever writes it downwards.
            $layer->forceFill([
                'quantity_remaining' => Quantity::subtract((string) $layer->quantity_remaining, $draw->quantity),
                'row_version' => (int) $layer->row_version + 1,
            ])->save();
        }
    }
}
