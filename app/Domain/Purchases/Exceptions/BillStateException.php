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

    /**
     * Only a bill can be corrected by a debit note. A debit note answers the
     * payable and the expense a bill raised, so pointing one at another note —
     * or at anything else carrying a bill number — has nothing to correct.
     */
    public static function notABill(Bill $document): self
    {
        return new self(sprintf(
            'Document %s is a %s, and only a bill can be corrected by a debit note.',
            $document->bill_no,
            $document->document_type
        ));
    }

    /**
     * The posting action was reached with a document that is not a debit note.
     * It is served by the debit-note route, so anything else arriving here is a
     * routing mistake rather than a state to reason about.
     */
    public static function notADebitNote(Bill $document): self
    {
        return new self(sprintf(
            'Document %s is a %s, so it is not a debit note that can be posted.',
            $document->bill_no,
            $document->document_type
        ));
    }

    /**
     * A draft bill has not reached the ledger, so there is no liability in the
     * books for a debit note to reduce. The bill is posted first, and a draft
     * bill is corrected by editing it.
     */
    public static function notPostedYet(Bill $bill): self
    {
        return new self(sprintf(
            'Bill %s has not been posted, so there is no liability in the ledger to correct.',
            $bill->bill_no
        ));
    }

    public static function isVoid(Bill $bill): self
    {
        return new self(sprintf(
            'Bill %s is void, so there is nothing left to correct.',
            $bill->bill_no
        ));
    }

    /**
     * A debit note that has already been reversed. A reversal is the last word
     * on a correction, so reversing the reversal — which would put the note
     * back into the ledger — is refused rather than treated as a fresh move.
     * This mirrors the stock module, which refuses to reverse a movement that
     * is itself a reversal.
     */
    public static function alreadyReversed(Bill $bill): self
    {
        return new self(sprintf(
            'Debit note %s has already been reversed, and a reversal cannot be reversed.',
            $bill->bill_no
        ));
    }

    /**
     * The note is not in a state a reversal can be asked of. Only a note that
     * is live in the books — approved, or partly settled — has an entry to take
     * back out; a draft or a void one has nothing to reverse.
     */
    public static function notReversible(Bill $bill, BillStatus $status): self
    {
        return new self(sprintf(
            'Debit note %s is %s, so it cannot be reversed; only a posted debit note can.',
            $bill->bill_no,
            $status->label()
        ));
    }

    /**
     * A debit note line has to name a line of the very bill it corrects.
     * Naming a line from elsewhere would reduce a payable that was never raised
     * here, so the link is refused rather than silently dropped.
     */
    public static function unknownLine(Bill $bill, int $lineId): self
    {
        return new self(sprintf(
            'Line %d is not a line of bill %s, so there is nothing to correct against it.',
            $lineId,
            $bill->bill_no
        ));
    }

    public static function nonPositiveDebit(string $description): self
    {
        return new self(sprintf(
            'A debit of [%s] must be for a quantity greater than zero.',
            $description
        ));
    }

    /**
     * More was debited than the bill still owes.
     *
     * The ceiling is the bill's own open amount — what it still owes after the
     * payments and any earlier debit notes already made against it — because a
     * note may only cancel a liability that is really there, never send the
     * payable below zero.
     *
     * @param  string  $requested  The note total asked for, as a decimal string.
     * @param  string  $available  What is still open on the bill.
     */
    public static function overDebit(Bill $bill, string $requested, string $available): self
    {
        return new self(sprintf(
            'Bill %s has only %s still open, and a debit note of %s was asked for.',
            $bill->bill_no,
            $available,
            $requested
        ));
    }
}
