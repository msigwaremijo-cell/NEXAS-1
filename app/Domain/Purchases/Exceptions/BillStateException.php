<?php

namespace App\Domain\Purchases\Exceptions;

use App\Domain\Purchases\Enums\BillStatus;
use App\Models\Bill;
use App\Models\Supplier;

/**
 * The bill is not in a state that permits the requested move.
 *
 * The distinction that matters here is the approval gate: a bill may not reach
 * the ledger from `draft` at all, only from `approved`, so an unapproved
 * liability can never appear in the payables balance.
 */
final class BillStateException extends PurchasesException
{
    public static function notDraft(Bill $bill, BillStatus $status): self
    {
        return new self(sprintf(
            'Bill %s is %s, so it can no longer be changed; only a draft can.',
            $bill->bill_no,
            $status->label()
        ));
    }

    public static function notAwaitingApproval(Bill $bill, BillStatus $status): self
    {
        return new self(sprintf(
            'Bill %s is %s, and only a bill pending approval can be approved or rejected.',
            $bill->bill_no,
            $status->label()
        ));
    }

    public static function notApproved(Bill $bill, BillStatus $status): self
    {
        return new self(sprintf(
            'Bill %s is %s; it must be approved before it can be posted to the ledger.',
            $bill->bill_no,
            $status->label()
        ));
    }

    public static function alreadyPosted(Bill $bill): self
    {
        return new self(sprintf(
            'Bill %s has already been posted against journal %s.',
            $bill->bill_no,
            (string) $bill->journal_id
        ));
    }

    public static function withoutLines(Bill $bill): self
    {
        return new self(sprintf(
            'Bill %s has no lines, so there is nothing to post to the ledger.',
            $bill->bill_no
        ));
    }

    public static function zeroValue(Bill $bill): self
    {
        return new self(sprintf(
            'Bill %s totals zero, and a zero-value bill cannot be posted.',
            $bill->bill_no
        ));
    }

    public static function partyBlocked(Supplier $supplier): self
    {
        return new self(sprintf(
            'Supplier %s is %s and cannot be given a new bill.',
            $supplier->name,
            $supplier->status->label()
        ));
    }

    public static function lineWithoutExpenseAccount(string $description): self
    {
        return new self(sprintf(
            'Bill line [%s] names no expense account, so the debit it stands for has nowhere to post.',
            $description
        ));
    }
}
