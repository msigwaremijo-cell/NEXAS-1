<?php

namespace App\Domain\Inventory\DTOs;

use App\Support\Money;
use App\Support\Quantity;

/**
 * What a bill line would clear out of goods received not invoiced, before
 * anything is written.
 *
 * A plan is worked out while the receipts are locked, so what it says cannot
 * change between the moment the line is priced and the moment it is written.
 * Two bills posted at the same moment are answered one after the other: the
 * second reads the receipts as the first left them, and cannot be given the
 * same goods twice.
 *
 * `shortfall` is the part of the line no open receipt could fund. It is zero
 * in ordinary trading; when it is not, the bill is refused rather than allowed
 * to clear more than was ever received and left unbilled.
 */
final class GrniDrawPlan
{
    /**
     * @param  array<int, GrniDraw>  $draws
     * @param  string  $quantity  The whole quantity the bill line asks to clear, positive.
     * @param  string  $shortfall  The part of it no open receipt could fund.
     */
    public function __construct(
        public readonly string $quantity,
        public readonly array $draws,
        public readonly string $shortfall,
    ) {}

    /**
     * Whether there was anything to clear at all. A bill line with no open
     * receipt is a bill for goods nobody has said arrived.
     */
    public function isEmpty(): bool
    {
        return $this->draws === [];
    }

    public function isShort(): bool
    {
        return Quantity::isPositive($this->shortfall);
    }

    /**
     * How much of the supplier's goods the line settles, across every receipt
     * it draws on.
     */
    public function clearedQuantity(): string
    {
        return Quantity::of(array_reduce(
            $this->draws,
            static fn (string $carry, GrniDraw $draw): string => Quantity::add($carry, $draw->quantity),
            Quantity::zero(),
        ));
    }

    /**
     * What the line takes out of goods received not invoiced. It is the sum of
     * each receipt's own share at that receipt's own price, so the liability
     * leaves at what the delivery was booked at rather than at what the bill
     * now says it was worth. The two differing is what the variance account is
     * for.
     */
    public function value(): Money
    {
        return Money::sum(array_map(
            static fn (GrniDraw $draw): Money => $draw->value,
            array_values($this->draws),
        ));
    }
}
