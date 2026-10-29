<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\GrniDraw;
use App\Domain\Inventory\DTOs\GrniDrawPlan;
use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Models\GrniAllocation;
use App\Models\StockMovement;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * Goods received not invoiced: what a purchase receipt credits and the bill for
 * it clears.
 *
 * A purchase is two events, not one. The goods arrive — the receipt moves the
 * stock and credits GRNI — and the invoice arrives later, at which point the
 * bill clears that liability and creates the payable. Nothing here receives
 * stock: a bill that also received it would count the quantity twice, open a
 * second layer at the same price, and value the same units twice against the
 * inventory account.
 *
 * Which receipts a bill line may clear is not the caller's to choose. It is the
 * oldest open receipts for the same supplier and the same product, drawn down
 * only as far as what is still received and unbilled.
 *
 * `(supplier, product)` is the scope because a liability is owed to one
 * supplier for one item, and a receipt that names no supplier — opening stock,
 * a customer return — is nobody's delivery and belongs to no bill.
 *
 * Two things about the order of work mirror FifoLayerLedger, and for the same
 * reasons.
 *
 * The receipts are read with a locking read, and the plan is worked out from
 * that read inside the caller's transaction, so two bills asked for at the same
 * moment are answered one after the other and cannot clear the same goods
 * twice.
 *
 * The plan is written down before it is applied. `grni_allocations` is a record
 * rather than a recalculation — what a bill cleared stays what it cleared — and
 * the appending is what makes "received and unbilled" a figure the next bill
 * can trust.
 *
 * Neither method opens a transaction of its own. They are only ever called from
 * inside one, and a plan taken outside the transaction that applies it would be
 * a plan about a picture that may already have changed.
 */
final class GrniLedger
{
    /**
     * Work out which receipts would fund a clear, oldest first.
     *
     * The receipts this read touches stay locked until the transaction around
     * it ends, so the plan cannot be overtaken while the bill line it belongs
     * to is being posted. Only receipts still posted are open: a receipt that
     * has been reversed put the goods back, so there is nothing owing for it.
     *
     * @param  string  $quantity  How much the bill line asks to clear, positive.
     */
    public function plan(
        int $companyId,
        int $supplierId,
        int $productId,
        string $quantity,
    ): GrniDrawPlan {
        $wanted = Quantity::of($quantity);

        /** @var Collection<int, StockMovement> $receipts */
        $receipts = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('supplier_id', $supplierId)
            ->where('product_id', $productId)
            ->where('movement_type', StockMovementType::Receipt->value)
            ->where('status', StockMovementStatus::Posted->value)
            ->orderBy('movement_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $draws = [];
        $outstanding = $wanted;

        foreach ($receipts as $receipt) {
            if (! Quantity::isPositive($outstanding)) {
                break;
            }

            // What is left of the delivery after every bill that has already
            // drawn on it. This is what makes a partial receipt and a partial
            // bill add up rather than overlap.
            $open = Quantity::subtract((string) $receipt->quantity, $receipt->clearedQuantity());

            if (! Quantity::isPositive($open)) {
                continue;
            }

            $take = Quantity::lesser($open, $outstanding);

            $draws[] = GrniDraw::of((int) $receipt->getKey(), $take, (string) $receipt->unit_cost);

            $outstanding = Quantity::subtract($outstanding, $take);
        }

        return new GrniDrawPlan($wanted, $draws, $outstanding);
    }

    /**
     * Write the plan down: one allocation per receipt the line drew on.
     *
     * The row is what makes the clear checkable afterwards rather than merely
     * intended, and its value is snapshotted at the receipt's own price so what
     * was cleared stays what was cleared.
     */
    public function apply(int $companyId, int $billLineId, GrniDrawPlan $plan): void
    {
        foreach ($plan->draws as $draw) {
            GrniAllocation::create([
                'company_id' => $companyId,
                'bill_line_id' => $billLineId,
                'receipt_movement_id' => $draw->receiptMovementId,
                'quantity' => $draw->quantity,
                'unit_cost' => $draw->unitCost,
                'value' => $draw->value->toDecimal(),
            ]);
        }
    }
}
