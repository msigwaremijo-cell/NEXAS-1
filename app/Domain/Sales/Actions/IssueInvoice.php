<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentLineCalculator;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves a draft invoice to issued by posting it to the ledger.
 *
 * The journal is the mirror of the document:
 *
 *   Dr  customer's receivable control account   the invoice total
 *   Cr  each line's revenue account             the line net of discount
 *   Cr  each tax code's output account          the tax on its lines
 *
 * The two sides agree by construction, but the balance is still checked by
 * PostJournal rather than assumed: the lines are read back out of the database
 * after pricing, so a line edited by somebody else between the two steps is
 * judged on what is actually stored.
 *
 * Issue is a one-way door. The invoice number is final, the document stops
 * being editable, and a mistake is corrected with a credit note rather than an
 * edit — which is why the guard below refuses anything that is not a draft.
 */
final class IssueInvoice
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        Invoice $invoice,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Invoice {
        return DB::transaction(function () use ($invoice, $userId, $idempotencyKey): Invoice {
            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->journal_id !== null) {
                throw InvoiceStateException::alreadyIssued($locked);
            }

            if (! $locked->status->isEditable()) {
                throw InvoiceStateException::notDraft($locked, $locked->status);
            }

            $companyId = (int) $locked->company_id;
            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw InvoiceStateException::withoutLines($locked);
            }

            $total = Money::of($locked->total);

            if (! $total->isPositive()) {
                throw InvoiceStateException::zeroValue($locked);
            }

            $customer = Customer::query()->whereKey($locked->customer_id)->firstOrFail();

            $journal = ($this->create)(new JournalEntryData(
                companyId: $companyId,
                postingDate: $locked->invoice_date->toDateString(),
                journalType: JournalType::Sales,
                lines: $this->journalLines($locked, $customer, $lines, $companyId),
                reference: $locked->invoice_no,
                memo: 'Invoice '.$locked->invoice_no.' issued to '.$customer->name,
                documentDate: $locked->invoice_date->toDateString(),
                currencyCode: $locked->currency_code,
                exchangeRate: $locked->exchange_rate,
                sourceType: Invoice::class,
                sourceId: (int) $locked->getKey(),
                createdByUserId: $userId,
            ));

            $posted = ($this->post)($journal, $idempotencyKey, $userId, $journal->row_version);

            $affected = Invoice::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    'status' => InvoiceStatus::Issued->value,
                    'journal_id' => $posted->getKey(),
                    'issued_at' => now(),
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::journal($locked);
            }

            $issued = Invoice::query()->findOrFail($locked->getKey());

            $this->audit->record('invoice.issued', $issued, [
                'status' => InvoiceStatus::Draft->value,
            ], [
                'status' => InvoiceStatus::Issued->value,
                'journal_no' => $posted->journal_no,
                'total' => $issued->total,
            ]);

            return $issued->load('lines');
        });
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     * @return array<int, JournalLineData>
     */
    private function journalLines(
        Invoice $invoice,
        Customer $customer,
        $lines,
        int $companyId,
    ): array {
        $postings = [
            new JournalLineData(
                accountId: (int) $customer->receivable_account_id,
                debit: Money::of($invoice->total),
                description: 'Invoice '.$invoice->invoice_no,
                partyType: PartyType::Customer,
                partyId: (int) $customer->getKey(),
                branchId: $invoice->branch_id === null ? null : (int) $invoice->branch_id,
            ),
        ];

        foreach ($lines as $line) {
            $net = Money::of($line->line_subtotal);

            if (! $net->isPositive()) {
                continue;
            }

            if ($line->revenue_account_id === null) {
                throw InvoiceStateException::lineWithoutRevenueAccount(
                    (string) $line->description
                );
            }

            $postings[] = new JournalLineData(
                accountId: (int) $line->revenue_account_id,
                credit: $net,
                description: (string) $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                branchId: $invoice->branch_id === null ? null : (int) $invoice->branch_id,
            );
        }

        foreach ($this->calculator->taxByAccount($companyId, $lines, purchase: false) as $accountId => $tax) {
            $postings[] = new JournalLineData(
                accountId: $accountId,
                credit: $tax,
                description: 'VAT on invoice '.$invoice->invoice_no,
                branchId: $invoice->branch_id === null ? null : (int) $invoice->branch_id,
            );
        }

        return $postings;
    }
}
