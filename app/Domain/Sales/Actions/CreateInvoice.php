<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentLineCalculator;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft invoice and prices its lines.
 *
 * Nothing here reaches the ledger: the document is a draft, and a draft is
 * allowed to be wrong. Issuing it is a separate action with its own rules.
 *
 * Two things are settled at draft time because the database freezes them on
 * issue: the invoice number (trg_journals_before_update pins a journal's own
 * number, and the same gapless series feeds this one) and the due date, which
 * is derived from the customer's payment terms rather than trusted from the
 * request.
 */
final class CreateInvoice
{
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumbering $numbering,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  invoice_date, optional due_date, branch_id,
     *                                            currency_code, exchange_rate, reference, notes, lines
     */
    public function __invoke(int $companyId, int $customerId, array $attributes, ?int $userId = null): Invoice
    {
        $lines = $attributes['lines'] ?? [];

        if ($lines === []) {
            throw new InvoiceStateException('An invoice needs at least one line before it can be created.');
        }

        return DB::transaction(function () use ($companyId, $customerId, $attributes, $lines, $userId): Invoice {
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->whereKey($customerId)
                ->firstOrFail();

            if (! $customer->status->acceptsNewDocuments()) {
                throw InvoiceStateException::partyBlocked($customer);
            }

            $invoiceDate = (string) $attributes['invoice_date'];
            $period = $this->calendar->resolve($companyId, $invoiceDate);
            $computed = $this->calculator->compute($companyId, $invoiceDate, $lines);

            $invoice = new Invoice([
                'company_id' => $companyId,
                'customer_id' => (int) $customer->getKey(),
                'branch_id' => $attributes['branch_id'] ?? null,
                'invoice_no' => $this->numbering->next(
                    $companyId,
                    DocumentType::Invoice,
                    $period->fiscal_year_id
                ),
                'document_type' => 'invoice',
                'status' => InvoiceStatus::Draft->value,
                'invoice_date' => $invoiceDate,
                'due_date' => $attributes['due_date']
                    ?? Carbon::parse($invoiceDate)->addDays($customer->payment_terms_days)->toDateString(),
                'currency_code' => $attributes['currency_code'] ?? $customer->currency_code,
                'exchange_rate' => $attributes['exchange_rate'] ?? '1',
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by_user_id' => $userId,
            ]);

            // Totals are absent from the fillable list, so they are written
            // directly from the calculator rather than mass-assigned.
            $invoice->subtotal = $computed->subtotal->toDecimal();
            $invoice->discount_total = $computed->discountTotal->toDecimal();
            $invoice->tax_total = $computed->taxTotal->toDecimal();
            $invoice->total = $computed->total->toDecimal();
            $invoice->amount_paid = '0.0000';

            $invoice->save();

            foreach ($computed->lines as $priced) {
                $line = new InvoiceLine([
                    'company_id' => $companyId,
                    'invoice_id' => $invoice->getKey(),
                    'line_no' => $priced['line_no'],
                    'product_id' => $priced['product_id'],
                    'description' => $priced['description'],
                    'quantity' => $priced['quantity'],
                    'unit_price' => $priced['unit_price'],
                    'discount_rate' => $priced['discount_rate'],
                    'tax_code_id' => $priced['tax_code_id'],
                    'revenue_account_id' => $priced['account_id'],
                ]);

                $line->discount_amount = $priced['discount_amount'];
                $line->tax_rate = $priced['tax_rate'];
                $line->tax_amount = $priced['tax_amount'];
                $line->line_subtotal = $priced['line_subtotal'];
                $line->line_total = $priced['line_total'];

                $line->save();
            }

            $this->audit->record('invoice.created', $invoice, null, [
                'invoice_no' => $invoice->invoice_no,
                'customer_id' => (int) $customer->getKey(),
                'total' => $invoice->total,
                'line_count' => count($computed->lines),
            ]);

            return $invoice->load('lines');
        });
    }
}
