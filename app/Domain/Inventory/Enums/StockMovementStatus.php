<?php

namespace App\Domain\Inventory\Enums;

/**
 * Mirrors the `stock_movements.status` column. Posted and reversed are the
 * only two states, and the move between them happens once: what a movement did
 * to the stock is a fact, and a fact is corrected by an opposite movement
 * rather than by an edit.
 */
enum StockMovementStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }
}
