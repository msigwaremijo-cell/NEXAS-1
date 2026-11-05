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
use App\Domain\Inventory\Actions\ReverseStockMovement;
use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Exceptions\InvoiceStateException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issues a credit note: the mirror image of the invoice it corrects.
 *
 *   Cr  the customer's receivable control account   the credit note total
 *   Dr  each line's revenue account                 the line net of discount
 *   Dr  each tax code's output account              the tax on its lines
 *
 * The account that was debited when the invoice was raised is credited here,
 * and each account credited then is debited now, so the two entries net to
 * nothing account by account. The journal type is `credit_note` so a report can
 * tell a correction apart from a return of the original sale.
 *
 * The goods come back too, and this is the part that needs care.
 *
 * A line that gives back the whole of an invoice line, and is the first credit
 * note to do so, reverses the original issue movement outright. That is the
 * faithful correction: the cost that left the inventory account comes back, and
 * the original movement is marked reversed rather than a second movement being
 * written beside it.
 *
 * A line that gives back only part of one — or gives back the rest of one that
 * an earlier credit note already returned part of — leaves the original issue
 * posted and writes a new receipt at the original issue's own unit cost. Stock
 * cannot be put back into the layers an issue drew on, because those records
 * are append-only and a layer only ever writes downwards, so a fresh layer is
 * opened at the price the stock left at. The value is identical; only the layer
 * holding it is new.
 *
 * The journal is posted before any of this, inside the same transaction, so a
 * credit note that cannot return its goods is refused as a whole rather than
 * leaving the ledger credited and the shelf untouched.
 *
 * Issue is a one-way door: the document stops being editable, and a mistake is
 * corrected by a second note rather than an edit, which is why the guard below
 * refuses anything that is not a draft.
 */
final class IssueCreditNote
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
        private readonly ReverseStockMovement $reverseMovement,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
    ) {}

    public function __invoke(
        Invoice $creditNote,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Invoice {
        return DB::transaction(function () use ($creditNote, $userId, $idempotencyKey): Invoice {
            $locked = Invoice::query()
                ->whereKey($creditNote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->journal_id !== null) {
                throw InvoiceStateException::alreadyIssued($locked);
            }

            if (! $locked->status->isEditable()) {
                throw InvoiceStateException::notDraft($locked, $locked->status);
            }

            // Reaching here through the wrong route would otherwise let an
            // ordinary invoice be posted twice, once as a sale and once as a
            // credit, so the document type is checked as well as the state.
            if ($locked->document_type !== 'credit_note') {
                throw InvoiceStateException::notACreditNote($locked);
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

            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->whereKey($locked->customer_id)
                ->firstOrFail();

            $journal = ($this->create)(new JournalEntryData(
                companyId: $companyId,
                postingDate: $locked->invoice_date->toDateString(),
                journalType: JournalType::CreditNote,
                lines: $this->journalLines($locked, $customer, $lines, $companyId),
                reference: $locked->invoice_no,
                memo: 'Credit note '.$locked->invoice_no.' for '.$customer->name,
                documentDate: $locked->invoice_date->toDateString(),
                currencyCode: $locked->currency_code,
                exchangeRate: $locked->exchange_rate,
                sourceType: Invoice::class,
                sourceId: (int) $locked->getKey(),
                createdByUserId: $userId,
            ));

            $posted = ($this->post)($journal, $idempotencyKey, $userId, $journal->row_version);

            // The goods come back with the credit. This runs after the journal
            // is posted but inside the same transaction, so a credit note whose
            // stock cannot be put back is refused whole rather than leaving the
            // ledger corrected and the shelf unchanged.
            $this->returnGoods($locked, $lines, $companyId, $userId);

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

            $this->audit->record('credit_note.issued', $issued, [
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
        Invoice $creditNote,
        Customer $customer,
        $lines,
        int $companyId,
    ): array {
        $branchId = $creditNote->branch_id === null ? null : (int) $creditNote->branch_id;

        $postings = [
            new JournalLineData(
                accountId: (int) $customer->receivable_account_id,
                credit: Money::of($creditNote->total),
                description: 'Credit note '.$creditNote->invoice_no,
                partyType: PartyType::Customer,
                partyId: (int) $customer->getKey(),
                branchId: $branchId,
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
                debit: $net,
                description: (string) $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                branchId: $branchId,
            );
        }

        // One posting per tax code rather than per account, exactly as the
        // invoice did, so the VAT return can read the tax back off the line.
        foreach ($this->calculator->taxByCode($companyId, $lines, purchase: false) as $tax) {
            $postings[] = new JournalLineData(
                accountId: $tax['account_id'],
                debit: $tax['amount'],
                description: 'VAT '.$tax['tax_code'].' on credit note '.$creditNote->invoice_no,
                taxCodeId: $tax['tax_code_id'],
                taxAmount: $tax['amount'],
                branchId: $branchId,
            );
        }

        return $postings;
    }

    /**
     * Puts the goods a credit note gives back onto the shelf.
     *
     * Only lines for items the company carries as stock move anything: a
     * service line names no product, and a product that is not carried as stock
     * is sold without a movement, so a credit note for it affects the ledger
     * alone. An entirely service credit note therefore needs no warehouse, and
     * the lookup is done lazily so a service company is never refused one.
     *
     * @param  Collection<int, InvoiceLine>  $lines
     */
    private function returnGoods(Invoice $creditNote, $lines, int $companyId, ?int $userId): void
    {
        $goods = [];

        foreach ($lines as $line) {
            $product = Product::stockItemFor(
                $companyId,
                $line->product_id === null ? null : (int) $line->product_id
            );

            if ($product !== null) {
                $goods[] = [$line, $product];
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

        foreach ($goods as [$line, $product]) {
            $this->returnLine($creditNote, $line, $product, $warehouse, $companyId, $userId);
        }
    }

    /**
     * Puts one stock line's quantity back, either by reversing the issue that
     * took it out or by writing a fresh receipt at the issue's own cost.
     */
    private function returnLine(
        Invoice $creditNote,
        InvoiceLine $line,
        Product $product,
        Warehouse $warehouse,
        int $companyId,
        ?int $userId,
    ): void {
        $originalLineId = $line->credit_note_for_line_id === null
            ? null
            : (int) $line->credit_note_for_line_id;

        $original = $originalLineId === null
            ? null
            : InvoiceLine::query()
                ->where('company_id', $companyId)
                ->whereKey($originalLineId)
                ->first();

        if ($original === null) {
            throw StockStateException::noIssueToReturn((string) $line->description);
        }

        // The issue this line's stock left on, found by the provenance the
        // invoice wrote onto the movement. It has to be posted and not already
        // reversed: a movement whose stock has already come back has nothing
        // left to give.
        $issue = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('source_type', InvoiceLine::class)
            ->where('source_id', $originalLineId)
            ->where('movement_type', StockMovementType::Issue->value)
            ->where('status', StockMovementStatus::Posted->value)
            ->whereDoesntHave('reversal')
            ->orderByDesc('id')
            ->first();

        if ($issue === null) {
            throw StockStateException::noIssueToReturn((string) $line->description);
        }

        $date = $creditNote->invoice_date->toDateString();
        $returned = Quantity::of((string) $line->quantity);
        $memo = 'Credit note '.$creditNote->invoice_no;

        $givesBack = Quantity::compare($returned, (string) $original->quantity) === 0;

        if ($givesBack && ! $this->earlierReturnExists($companyId, $originalLineId, (int) $creditNote->getKey())) {
            // The whole line comes back and nothing has come back of it before,
            // so the original issue is reversed outright: the cost re-enters
            // the inventory account and the movement is marked reversed rather
            // than a second movement being written beside it.
            ($this->reverseMovement)(
                companyId: $companyId,
                movementId: (int) $issue->getKey(),
                movementDate: $date,
                memo: $memo,
                userId: $userId,
            );

            return;
        }

        // A part of the line comes back, so the original issue stays posted and
        // a new receipt is written at the price the stock left at. Putting the
        // units back into the layers that funded the issue is not possible —
        // those records are append-only — so a fresh layer is opened instead,
        // holding the same value.
        $unitCost = Money::of((string) $issue->unit_cost);
        $accounts = $this->recorder->accounts($product);

        $movement = $this->recorder->record(new StockMovementDraft(
            companyId: $companyId,
            product: $product,
            warehouse: $warehouse,
            type: StockMovementType::Receipt,
            movementDate: $date,
            quantity: $returned,
            totalCost: $unitCost->times($returned),
            debitAccountId: $accounts['inventory'],
            creditAccountId: $accounts['cost'],
            reference: $creditNote->invoice_no,
            memo: $memo,
            userId: $userId,
            sourceType: InvoiceLine::class,
            sourceId: (int) $line->getKey(),
        ));

        $this->layers->open(
            companyId: $companyId,
            productId: (int) $product->getKey(),
            warehouseId: (int) $warehouse->getKey(),
            receiptMovementId: (int) $movement->getKey(),
            receivedOn: $date,
            quantity: $returned,
            unitCost: $unitCost->toDecimal(),
        );
    }

    /**
     * Whether an issued credit note has already given part of this original
     * line back.
     *
     * The current note is excluded because it is still a draft while it is
     * being issued; only notes that have reached the ledger count, because only
     * they have already moved the stock.
     */
    private function earlierReturnExists(int $companyId, int $originalLineId, int $creditNoteId): bool
    {
        return InvoiceLine::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->where('invoice_lines.company_id', $companyId)
            ->where('invoice_lines.credit_note_for_line_id', $originalLineId)
            ->where('invoices.document_type', 'credit_note')
            ->where('invoices.status', InvoiceStatus::Issued->value)
            ->where('invoice_lines.invoice_id', '!=', $creditNoteId)
            ->exists();
    }
}
