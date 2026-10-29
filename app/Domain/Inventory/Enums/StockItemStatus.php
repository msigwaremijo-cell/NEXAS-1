<?php

namespace App\Domain\Inventory\Enums;

/**
 * Whether an item of master data may still take part in new movements.
 *
 * Nothing is deleted: a product that has been withdrawn keeps its movements
 * and its layers, because the cost of the stock that moved through it is part
 * of the history of the books. What the status decides is whether it may be
 * named on a new one.
 */
enum StockItemStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function acceptsNewMovements(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
