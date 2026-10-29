<?php

namespace App\Domain\Inventory\Enums;

/**
 * Mirrors the `stock_movements.movement_type` column comment
 * ('receipt|issue|adjustment|transfer_in|transfer_out').
 *
 * The direction of a movement is carried twice on purpose: once by the type,
 * which says what happened, and once by the sign of the quantity, which is
 * what makes the quantity on hand a single SUM over the movement table. The
 * two are kept in step by a CHECK constraint, so a row whose sign contradicts
 * its type never reaches the database.
 */
enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Adjustment = 'adjustment';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    /**
     * The type a movement takes when a movement of this type is reversed.
     *
     * A reversal undoes a direction rather than repeating it, so a receipt is
     * answered by an issue and the two net to nothing. An adjustment answers
     * itself because it already carries whichever sign it needs.
     */
    public function reversed(): self
    {
        return match ($this) {
            self::Receipt => self::Issue,
            self::Issue => self::Receipt,
            self::TransferIn => self::TransferOut,
            self::TransferOut => self::TransferIn,
            self::Adjustment => self::Adjustment,
        };
    }

    /**
     * Whether a movement of this type puts stock in or takes it out.
     *
     * The one type that is neither is an adjustment, which is told by its own
     * sign: a stock count that finds more than the books say is a put-in, and
     * one that finds less is a take-out.
     */
    public function addsStock(): bool
    {
        return match ($this) {
            self::Receipt, self::TransferIn => true,
            self::Issue, self::TransferOut, self::Adjustment => false,
        };
    }

    public function isTransfer(): bool
    {
        return $this === self::TransferIn || $this === self::TransferOut;
    }

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Goods received',
            self::Issue => 'Goods issued',
            self::Adjustment => 'Stock adjustment',
            self::TransferIn => 'Transfer in',
            self::TransferOut => 'Transfer out',
        };
    }
}
