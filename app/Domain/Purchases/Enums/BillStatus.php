<?php

namespace App\Domain\Purchases\Enums;

/**
 * Mirrors the `bills.status` column comment
 * ('draft|pending_approval|approved|partially_paid|paid|void').
 *
 * A bill carries an extra gate that a sales invoice does not: it must be
 * approved before it can be posted to the ledger. `reversed` has since been
 * added for the correction a debit note answers with — the column is a plain
 * VARCHAR, so as on `journals.journal_type` this enum is the authority rather
 * than the original comment.
 */
enum BillStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /**
     * A posted debit note whose entry has been taken back out of the books by
     * a reversal. Like a reversed journal or a reversed stock movement it is
     * kept as it was and marked, never removed, so the correction reads as a
     * second fact rather than as an absence.
     */
    case Reversed = 'reversed';

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
            self::Reversed => 'Reversed',
        };
    }
}
