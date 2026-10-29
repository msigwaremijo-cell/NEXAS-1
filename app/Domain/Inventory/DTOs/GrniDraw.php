<?php

namespace App\Domain\Inventory\DTOs;

use App\Support\Money;
use App\Support\Quantity;

/**
 * One receipt's share of what a single bill line clears.
 *
 * The quantity is always positive: it is how much of that receipt the line
 * settled, and the direction is not in question — one bill line only ever
 * clears goods received, never adds any. The price is the receipt's own, so
 * what the line takes out of goods received not invoiced is what the delivery
 * was booked at rather than what the bill now says it was worth. The two
 * differing is the whole reason a variance account exists.
 */
final class GrniDraw
{
    public function __construct(
        public readonly int $receiptMovementId,
        public readonly string $quantity,
        public readonly string $unitCost,
        public readonly Money $value,
    ) {}

    /**
     * Price a quantity out of a receipt at the receipt's own cost.
     */
    public static function of(int $receiptMovementId, string $quantity, string $unitCost): self
    {
        $quantity = Quantity::of($quantity);
        $cost = Money::of($unitCost);

        return new self(
            receiptMovementId: $receiptMovementId,
            quantity: $quantity,
            unitCost: $cost->toDecimal(),
            value: $cost->times($quantity),
        );
    }
}
