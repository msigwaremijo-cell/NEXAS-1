<?php

namespace App\Domain\Sales\Exceptions;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;

/**
 * The invoice is not in a state that permits the requested move.
 */
final class InvoiceStateException extends SalesException
{
    public static function notDraft(Invoice $invoice, InvoiceStatus $status): self
    {
        return new self(sprintf(
            'Invoice %s is %s, so it can no longer be changed or issued; only a draft can.',
            $invoice->invoice_no,
            $status->label()
        ));
    }

    public static function withoutLines(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice %s has no lines, so there is nothing to post to the ledger.',
            $invoice->invoice_no
        ));
    }

    public static function zeroValue(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice %s totals zero, and a zero-value invoice cannot be posted.',
            $invoice->invoice_no
        ));
    }

    public static function alreadyIssued(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice %s has already been issued against journal %s.',
            $invoice->invoice_no,
            (string) $invoice->journal_id
        ));
    }

    public static function partyBlocked(Customer $customer): self
    {
        return new self(sprintf(
            'Customer %s is %s and cannot be given a new invoice.',
            $customer->name,
            $customer->status->label()
        ));
    }

    public static function lineWithoutRevenueAccount(string $description): self
    {
        return new self(sprintf(
            'Invoice line [%s] names no revenue account, so the credit it stands for has nowhere to post.',
            $description
        ));
    }

    /**
     * Only a sale can be credited. A credit note corrects the revenue and the
     * receivable an invoice raised, so pointing one at another note — or at
     * anything else carrying an invoice number — has nothing to correct.
     */
    public static function notAnInvoice(Invoice $document): self
    {
        return new self(sprintf(
            'Document %s is a %s, and only an invoice can be credited.',
            $document->invoice_no,
            $document->document_type
        ));
    }

    /**
     * The credit-note document is not a credit note. The issuing action is
     * reached through the credit-note route, so anything else arriving here is
     * a routing mistake rather than a state to reason about.
     */
    public static function notACreditNote(Invoice $document): self
    {
        return new self(sprintf(
            'Document %s is a %s, so it is not a credit note that can be issued.',
            $document->invoice_no,
            $document->document_type
        ));
    }

    /**
     * A draft has not reached the ledger, so there is nothing in the books for
     * a credit note to reverse. The invoice is issued first.
     */
    public static function notIssuedYet(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice %s has not been issued, so there is nothing in the ledger to credit.',
            $invoice->invoice_no
        ));
    }

    public static function isVoid(Invoice $invoice): self
    {
        return new self(sprintf(
            'Invoice %s is void, so there is nothing left to credit.',
            $invoice->invoice_no
        ));
    }

    /**
     * A credit note line has to name a line of the very invoice it corrects.
     * Naming a line from elsewhere would credit money that was never billed
     * here, so the link is refused rather than silently dropped.
     */
    public static function unknownLine(Invoice $invoice, int $lineId): self
    {
        return new self(sprintf(
            'Line %d is not a line of invoice %s, so there is nothing to credit against it.',
            $lineId,
            $invoice->invoice_no
        ));
    }

    public static function nonPositiveCredit(string $description): self
    {
        return new self(sprintf(
            'A credit of [%s] must be for a quantity greater than zero.',
            $description
        ));
    }

    /**
     * More was credited than was ever billed.
     *
     * The line's whole quantity, less whatever earlier credit notes have
     * already given back, is what is left; asking for more than that would
     * credit revenue the company never recognised and send the receivable
     * below what the customer ever owed.
     *
     * @param  string  $requested  The quantity asked for, as a decimal string.
     * @param  string  $available  What is still creditable on the line.
     */
    public static function overCredit(string $description, string $requested, string $available): self
    {
        return new self(sprintf(
            'Line [%s] has only %s left to credit, and %s was asked for.',
            $description,
            $available,
            $requested
        ));
    }
}
