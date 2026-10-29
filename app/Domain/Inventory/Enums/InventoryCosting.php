<?php

namespace App\Domain\Inventory\Enums;

/**
 * How the cost of stock that leaves is worked out.
 *
 * The choice is a company policy rather than a per-movement decision, because
 * two movements of the same item cannot be valued by different rules without
 * the inventory account ceasing to mean anything.
 *
 * Only FirstInFirstOut is implemented: `stock_layers` records what each
 * receipt cost and how much of it is left, which is exactly the state FIFO
 * needs. WeightedAverage is named here so that a company policy asking for it
 * is refused out loud rather than quietly answered with FIFO numbers.
 */
enum InventoryCosting: string
{
    case Fifo = 'fifo';
    case WeightedAverage = 'weighted_average';

    public function isImplemented(): bool
    {
        return $this === self::Fifo;
    }

    public function label(): string
    {
        return match ($this) {
            self::Fifo => 'First in, first out',
            self::WeightedAverage => 'Weighted average',
        };
    }
}
