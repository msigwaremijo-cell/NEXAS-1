<?php

namespace App\Domain\Inventory\DTOs;

use App\Support\Money;
use App\Support\Quantity;

/**
 * What a draw on the stock would take, before anything is written.
 *
 * A plan is worked out while the layers are locked, so what it says cannot
 * change between the moment an issue is priced and the moment it is written.
 * That is deliberate: two issues asked for at the same time are answered with
 * two plans over the same locked layers rather than with two plans over a
 * picture each of them read separately.
 *
 * `shortfall` is the part of the request no layer could fund. It is zero in
 * ordinary trading; when it is not, the company's policy on negative stock
 * decides whether the movement is refused or the unfunded part is carried at
 * no cost.
 */
final class LayerDrawPlan
{
    /**
     * @param  array<int, LayerDraw>  $draws
     * @param  string  $quantity  The whole quantity asked for, positive.
     * @param  string  $shortfall  The part of it no layer could fund.
     */
    public function __construct(
        public readonly string $quantity,
        public readonly array $draws,
        public readonly string $shortfall,
    ) {}

    public function isShort(): bool
    {
        return Quantity::isPositive($this->shortfall);
    }

    /**
     * What the layers actually cost. When the plan is short this is less than
     * the request, because the unfunded part came from nowhere and so cost
     * nothing.
     */
    public function totalCost(): Money
    {
        return Money::sum(array_map(
            static fn (LayerDraw $draw): Money => $draw->totalCost,
            array_values($this->draws),
        ));
    }

    /**
     * The cost of one unit across the whole draw. It is the plan's total over
     * the quantity asked for, not over the quantity the layers could fund, so
     * that what a movement records per unit agrees with what it recorded in
     * total.
     */
    public function unitCost(): Money
    {
        if (Quantity::isZero($this->quantity)) {
            return Money::zero();
        }

        return $this->totalCost()->dividedBy($this->quantity);
    }
}
