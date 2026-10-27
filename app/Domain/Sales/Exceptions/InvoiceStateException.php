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
}
