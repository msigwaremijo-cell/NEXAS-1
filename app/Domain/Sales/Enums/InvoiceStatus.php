<?php

namespace App\Domain\Sales\Enums;

/**
 * Mirrors the `invoices.status` column comment
 * ('draft|issued|partially_paid|paid|void').
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /**
     * Only a draft may be changed freely. Once issued the document posts to
     * the ledger, so corrections go through a credit note.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * True when the invoice still expects money.
     */
    public function isOpen(): bool
    {
        return $this === self::Issued || $this === self::PartiallyPaid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }
}
