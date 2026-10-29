<?php

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Models\Journal;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Quantity;

/**
 * Everything writing one stock movement needs.
 *
 * The quantity is signed, because that is how the direction is stored: a
 * receipt is positive, an issue is negative, and an adjustment carries
 * whichever sign it was counted at. The type has to agree with the sign, and
 * chk_stock_movements_sign is what makes that a fact of the schema.
 *
 * The two account ids are the whole of the accounting. A movement moves value
 * between exactly two accounts — the product's own inventory account and
 * whatever it was met by — so the movement says which is debited and which is
 * credited rather than handing over a set of journal lines to build. A
 * movement worth nothing (stock that arrived free, or units costed at nothing
 * because the stock was allowed to go negative) names no accounts at all and
 * posts no journal: there is no value for the ledger to record.
 *
 * `unitCost` is deliberately absent. It is derived from the total over the
 * quantity, so a caller cannot state a per-unit price that disagrees with the
 * amount it is moving.
 *
 * `supplierId` is set only on a purchase receipt. It is what lets a bill find
 * the receipts its own supplier has delivered and clear them, and it is the
 * reason every other movement leaves the column empty: a bill must never be
 * able to settle a delivery that is not owed for.
 */
final class StockMovementDraft
{
    public function __construct(
        public readonly int $companyId,
        public readonly Product $product,
        public readonly Warehouse $warehouse,
        public readonly StockMovementType $type,
        public readonly string $movementDate,
        public readonly string $quantity,
        public readonly Money $totalCost,
        public readonly ?int $debitAccountId = null,
        public readonly ?int $creditAccountId = null,
        public readonly ?int $supplierId = null,
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
        public readonly ?int $userId = null,
        public readonly ?StockMovement $reverses = null,
        public readonly ?Journal $journal = null,
    ) {}

    /**
     * Whether this movement has an entry of its own to make.
     *
     * Two things have to be true. The movement has to be worth something: stock
     * that arrived free, and units costed at nothing because the stock was
     * allowed to go negative, have no value to add to the inventory account.
     * And it has to name both the accounts the value moves between, which a
     * transfer does not — stock crossing between two of the company's own
     * warehouses leaves the company no richer and no poorer, so there is no
     * second account for the value to sit on.
     *
     * A movement carrying `journal` therefore needs neither: its entry already
     * exists, because a reversal is answered by a mirror image of the entry it
     * corrects rather than by a new entry of its own.
     */
    public function carriesValue(): bool
    {
        return $this->totalCost->isPositive()
            && $this->debitAccountId !== null
            && $this->creditAccountId !== null;
    }

    /**
     * The price of one unit, rounded to the money scale.
     */
    public function unitCost(): Money
    {
        $quantity = Quantity::absolute($this->quantity);

        if (Quantity::isZero($quantity)) {
            return Money::zero();
        }

        return $this->totalCost->dividedBy($quantity);
    }

    /**
     * What a ledger line says. The caller's reference is preferred, and a
     * movement that arrived without one is described by what it did and to
     * which item, so the entry is readable without opening the movement.
     */
    public function description(): string
    {
        return $this->memo ?? $this->type->label().': '.$this->product->sku;
    }
}
