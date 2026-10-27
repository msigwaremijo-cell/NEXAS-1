<?php

namespace App\Domain\Purchases\Enums;

/**
 * Mirrors the `bills.status` column comment
 * ('draft|pending_approval|approved|partially_paid|paid|void').
 *
 * A bill carries an extra gate that a sales invoice does not: it must be
 * approved before it can be posted to the ledger.
 */
enum BillStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Only an approved or partly paid bill is part of the payables balance.
     */
    public function isOpen(): bool
    {
        return $this === self::Approved || $this === self::PartiallyPaid;
    }

    public function isPostable(): bool
    {
        return $this === self::Approved;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }
}
