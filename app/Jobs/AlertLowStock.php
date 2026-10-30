<?php

namespace App\Jobs;

use App\Domain\Inventory\Enums\ProductType;
use App\Domain\Inventory\Enums\StockItemStatus;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Support\Enums\OutboxStatus;
use App\Support\Quantity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Raises an alert for every watched item that has fallen to or below its
 * reorder level.
 *
 * What is on hand is read from the layers rather than from a stored balance —
 * `SUM(quantity_remaining)` across every warehouse the item is kept in — for
 * the same reason the valuation report does: the layers are the one place the
 * quantity is recorded, and a second copy could disagree with them.
 *
 * The alert is an `outbox_events` row, written in the same transaction as the
 * decision, so a queue that is down delays the alert rather than losing it.
 * That is the outbox's whole purpose and this is its first producer.
 *
 * Only goods that are still active and that somebody is watching are
 * considered. A service holds no stock, an inactive item has been retired, and
 * a null reorder level means nobody asked to be told — none of the three is a
 * low shelf.
 *
 * An item that already has an outstanding alert is left alone, so a job that
 * runs on a schedule does not pile up a fresh row for every tick while the
 * shelf stays low. Once that alert is processed, a later run may raise another
 * if the item is still short.
 */
class AlertLowStock implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
    ) {}

    public function handle(): void
    {
        $onHand = $this->onHandByProduct();

        $watched = Product::query()
            ->where('company_id', $this->companyId)
            ->where('product_type', ProductType::Goods->value)
            ->where('status', StockItemStatus::Active->value)
            ->whereNotNull('reorder_level')
            ->orderBy('sku')
            ->get();

        DB::transaction(function () use ($watched, $onHand): void {
            foreach ($watched as $product) {
                $have = Quantity::of((string) ($onHand[(int) $product->getKey()] ?? '0'));
                $level = Quantity::of((string) $product->reorder_level);

                // At the level counts as low: the point of the level is to act
                // before the shelf is empty, not to wait until it is.
                if (Quantity::compare($have, $level) > 0) {
                    continue;
                }

                $this->raise($product, $have, $level);
            }
        });
    }

    /**
     * What the layers still hold per item, across every place it is kept.
     *
     * @return array<int|string, string>
     */
    private function onHandByProduct(): array
    {
        return DB::table('stock_layers')
            ->where('company_id', $this->companyId)
            ->selectRaw('product_id, SUM(quantity_remaining) AS on_hand')
            ->groupBy('product_id')
            ->pluck('on_hand', 'product_id')
            ->all();
    }

    private function raise(Product $product, string $have, string $level): void
    {
        $alreadyOwed = OutboxEvent::query()
            ->where('company_id', $this->companyId)
            ->where('event_type', 'inventory.low_stock')
            ->where('aggregate_type', 'product')
            ->where('aggregate_id', (int) $product->getKey())
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
            ->exists();

        if ($alreadyOwed) {
            return;
        }

        OutboxEvent::create([
            'company_id' => $this->companyId,
            'event_type' => 'inventory.low_stock',
            'aggregate_type' => 'product',
            'aggregate_id' => (int) $product->getKey(),
            'payload' => [
                'product_id' => (int) $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'on_hand' => $have,
                'reorder_level' => $level,
            ],
            'status' => OutboxStatus::Pending->value,
            'available_at' => now(),
        ]);
    }
}
