<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentLineCalculator;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft credit note against an issued invoice.
 *
 * A credit note is not a new sale with a minus sign: every line on it gives
 * back part or all of one line of the invoice it corrects. That is why a line
 * may only be asked for by naming the original invoice line and a quantity —
 * the price, the discount, the tax code and the revenue account all come from
 * the original line rather than from the caller. A client that could name its
 * own price could credit a sale at a figure the company never charged.
 *
 * The link to the original line is also what makes over-crediting impossible
 * to get wrong. How much is left to credit is the original line's quantity
 * less the sum of every non-void credit note line already pointed at it, so a
 * second note that would take the total past what was billed is refused with a
 * sentence rather than quietly allowed.
 *
 * Nothing here reaches the ledger: the document is a draft, and issuing it is
 * a separate action with its own permission.
 */
final class CreateCreditNote
{
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumbering $numbering,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  invoice_date, reference, notes, lines
     */
    public function __invoke(int $companyId, Invoice $invoice, array $attributes, ?int $userId = null): Invoice
    {
        return DB::transaction(function () use ($companyId, $invoice, $attributes, $userId): Invoice {
            $target = Invoice::query()
                ->where('company_id', $companyId)
                ->whereKey($invoice->getKey())
                ->firstOrFail();

            if ($target->document_type !== 'invoice') {
                throw InvoiceStateException::notAnInvoice($target);
            }

            // A draft has not reached the ledger, so there is nothing to
            // credit. Correcting it is a matter of editing it, not crediting it.
            if ($target->journal_id === null) {
                throw InvoiceStateException::notIssuedYet($target);
            }

            if ($target->status === InvoiceStatus::Void) {
                throw InvoiceStateException::isVoid($target);
            }

            $requested = $attributes['lines'] ?? [];

            if ($requested === []) {
                throw new InvoiceStateException('A credit note needs at least one line before it can be created.');
            }

            $invoiceDate = (string) ($attributes['invoice_date'] ?? $target->invoice_date->toDateString());
            $period = $this->calendar->resolve($companyId, $invoiceDate);

            $originalLines = $target->lines()->get()->keyBy(
                static fn (InvoiceLine $line): int => (int) $line->getKey()
            );

            $creditLines = [];
            $links = [];

            foreach ($requested as $entry) {
                $lineId = (int) ($entry['invoice_line_id'] ?? 0);
                $original = $originalLines->get($lineId);

                if ($original === null) {
                    throw InvoiceStateException::unknownLine($target, $lineId);
                }

                $quantity = Quantity::of((string) $entry['quantity']);

                if (! Quantity::isPositive($quantity)) {
                    throw InvoiceStateException::nonPositiveCredit((string) $original->description);
                }

                $available = Quantity::subtract(
                    (string) $original->quantity,
                    $this->creditedAgainst($companyId, $lineId)
                );

                if (Quantity::compare($quantity, $available) > 0) {
                    throw InvoiceStateException::overCredit(
                        (string) $original->description,
                        $quantity,
                        $available
                    );
                }

                // Every figure but the quantity is the original line's, so the
                // credit is priced exactly as the sale was. The discount is
                // carried as a rate rather than an amount so a part-credit
                // takes its share of the discount rather than all of it.
                $creditLines[] = [
                    'description' => (string) $original->description,
                    'quantity' => $quantity,
                    'unit_price' => (string) $original->unit_price,
                    'discount_rate' => (string) $original->discount_rate,
                    'tax_code_id' => $original->tax_code_id === null ? null : (int) $original->tax_code_id,
                    'product_id' => $original->product_id === null ? null : (int) $original->product_id,
                    'account_id' => $original->revenue_account_id === null
                        ? null
                        : (int) $original->revenue_account_id,
                ];

                $links[] = $lineId;
            }

            $computed = $this->calculator->compute($companyId, $invoiceDate, $creditLines);

            $creditNote = new Invoice([
                'company_id' => $companyId,
                'customer_id' => (int) $target->customer_id,
                'branch_id' => $target->branch_id,
                'invoice_no' => $this->numbering->next(
                    $companyId,
                    DocumentType::CreditNote,
                    $period->fiscal_year_id
                ),
                'document_type' => 'credit_note',
                'status' => InvoiceStatus::Draft->value,
                'invoice_date' => $invoiceDate,
                // A credit note is not a debt with terms: it reduces what is
                // owed the moment it is issued, so it falls due on its own date.
                'due_date' => $invoiceDate,
                'currency_code' => $target->currency_code,
                'exchange_rate' => $target->exchange_rate,
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'credit_note_for_invoice_id' => (int) $target->getKey(),
                'created_by_user_id' => $userId,
            ]);

            // Totals are absent from the fillable list, so they are written
            // directly from the calculator rather than mass-assigned.
            $creditNote->subtotal = $computed->subtotal->toDecimal();
            $creditNote->discount_total = $computed->discountTotal->toDecimal();
            $creditNote->tax_total = $computed->taxTotal->toDecimal();
            $creditNote->total = $computed->total->toDecimal();
            $creditNote->amount_paid = '0.0000';

            $creditNote->save();

            // compute() prices the lines in the order they were handed over,
            // so the links line up with the priced lines by position.
            foreach (array_values($computed->lines) as $index => $priced) {
                $line = new InvoiceLine([
                    'company_id' => $companyId,
                    'invoice_id' => $creditNote->getKey(),
                    'line_no' => $priced['line_no'],
                    'product_id' => $priced['product_id'],
                    'description' => $priced['description'],
                    'quantity' => $priced['quantity'],
                    'unit_price' => $priced['unit_price'],
                    'discount_rate' => $priced['discount_rate'],
                    'tax_code_id' => $priced['tax_code_id'],
                    'revenue_account_id' => $priced['account_id'],
                    'credit_note_for_line_id' => $links[$index],
                ]);

                $line->discount_amount = $priced['discount_amount'];
                $line->tax_rate = $priced['tax_rate'];
                $line->tax_amount = $priced['tax_amount'];
                $line->line_subtotal = $priced['line_subtotal'];
                $line->line_total = $priced['line_total'];

                $line->save();
            }

            $this->audit->record('credit_note.created', $creditNote, null, [
                'invoice_no' => $creditNote->invoice_no,
                'credits_invoice' => $target->invoice_no,
                'customer_id' => (int) $target->customer_id,
                'total' => $creditNote->total,
                'line_count' => count($computed->lines),
            ]);

            return $creditNote->load('lines');
        });
    }

    /**
     * How much of one original invoice line has already been credited.
     *
     * Only the company's own credit notes count, and only the live ones: a
     * voided note gives nothing back, so it must not eat into what is left to
     * credit. The sum is over the credit note lines that point at the original
     * line, which is what the link column exists for.
     */
    private function creditedAgainst(int $companyId, int $originalLineId): string
    {
        $credited = InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoice_lines.company_id', $companyId)
            ->where('invoice_lines.credit_note_for_line_id', $originalLineId)
            ->where('invoices.document_type', 'credit_note')
            ->where('invoices.status', '!=', InvoiceStatus::Void->value)
            ->sum('invoice_lines.quantity');

        return Quantity::of((string) $credited);
    }
}
