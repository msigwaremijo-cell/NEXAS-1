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
use App\Domain\Inventory\Actions\IssueStock;
use App\Domain\Inventory\DTOs\StockIssueData;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Quantity;
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
 * Issuing also takes the goods off the shelf. A line for an item the company
 * carries as stock is a real movement of real goods, so it is issued from the
 * company's default warehouse through the same IssueStock the stock routes use:
 * the cost leaves the inventory account and lands on the product's own cost of
 * sales account. A company that has not named a default warehouse is refused
 * with a sentence rather than left to guess where the stock went.
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
        private readonly IssueStock $issueStock,
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

            // The goods leave as the sale is recognised. This is inside the
            // same transaction as the journal, so a sale that cannot be funded
            // — no default warehouse, or no stock when the company forbids
            // negatives — is refused as a whole rather than half-posted.
            $this->issueGoods($locked, $lines, $companyId, $userId);

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
                throw ConcurrentModificationException::row(
                    sprintf('Invoice %s', $locked->invoice_no),
                    (int) $locked->row_version,
                );
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

        // One posting per tax code rather than per account: the code is written
        // onto the line so the ledger says which tax it is, which is what the
        // VAT return reads back. Two codes settling into the same control
        // account therefore produce two lines, and the return can still tell
        // the standard-rated tax from the rest.
        foreach ($this->calculator->taxByCode($companyId, $lines, purchase: false) as $tax) {
            $postings[] = new JournalLineData(
                accountId: $tax['account_id'],
                credit: $tax['amount'],
                description: 'VAT '.$tax['tax_code'].' on invoice '.$invoice->invoice_no,
                taxCodeId: $tax['tax_code_id'],
                taxAmount: $tax['amount'],
                branchId: $invoice->branch_id === null ? null : (int) $invoice->branch_id,
            );
        }

        return $postings;
    }

    /**
     * Issues the goods lines off the shelf.
     *
     * Only lines for items the company carries as stock move anything: a
     * service line names no product, and a product the company does not hold
     * as stock is sold without a movement. An invoice made up entirely of
     * those needs no warehouse at all, so the lookup is done lazily — after
     * the last line, not before the first — and a company that sells a service
     * is never refused for naming no default warehouse.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     */
    private function issueGoods(Invoice $invoice, $lines, int $companyId, ?int $userId): void
    {
        $goods = [];

        foreach ($lines as $line) {
            $product = Product::stockItemFor(
                $companyId,
                $line->product_id === null ? null : (int) $line->product_id
            );

            if ($product !== null) {
                // The line is carried along with the goods so the issue movement
                // can name it as its source. That is what lets a later credit
                // note find exactly the movement this line caused, rather than
                // guessing from the product and the date.
                $goods[] = [$product, Quantity::of((string) $line->quantity), (int) $line->getKey()];
            }
        }

        if ($goods === []) {
            return;
        }

        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->first();

        if ($warehouse === null) {
            throw StockStateException::noDefaultWarehouse();
        }

        foreach ($goods as [$product, $quantity, $lineId]) {
            ($this->issueStock)(new StockIssueData(
                companyId: $companyId,
                productId: (int) $product->getKey(),
                warehouseId: (int) $warehouse->getKey(),
                movementDate: $invoice->invoice_date->toDateString(),
                quantity: $quantity,
                reference: $invoice->invoice_no,
                memo: 'Invoice '.$invoice->invoice_no,
                userId: $userId,
                sourceType: InvoiceLine::class,
                sourceId: $lineId,
            ));
        }
    }
}
