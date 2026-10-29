<?php

namespace App\Domain\Inventory\DTOs;

use App\Support\Money;
use App\Support\Quantity;

/**
 * One layer's share of a single draw on the stock.
 *
 * The quantity is always positive: it is how much left the layer, and which
 * way the stock went lives on the movement that asked for it. The cost is the
 * price that layer was bought at, which is the whole point of costing stock in
 * layers — an issue made of two receipts is worth two different prices.
 */
final class LayerDraw
{
    public function __construct(
        public readonly int $layerId,
        public readonly string $quantity,
        public readonly string $unitCost,
        public readonly Money $totalCost,
    ) {}

    /**
     * Price a quantity out of a layer at the layer's own cost.
     */
    public static function of(int $layerId, string $quantity, string $unitCost): self
    {
        $quantity = Quantity::of($quantity);
        $cost = Money::of($unitCost);

        return new self(
            layerId: $layerId,
            quantity: $quantity,
            unitCost: $cost->toDecimal(),
            totalCost: $cost->times($quantity),
        );
    }
}
