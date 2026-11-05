<?php

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentLineCalculator;
use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Inventory\Services\StockPolicy;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\GrniAllocation;
use App\Models\Product;
use App\Models\StockLayer;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a supplier debit note: the mirror image of the bill it corrects.
 *
 *   Dr  the supplier's payable control account   the debit note total
 *   Cr  each expense line's account              the line net of discount
 *   Cr  the item's cost of sales account         the stock a return releases
 *   Cr  purchase variance                        where the note is dearer than the receipt
 *   Dr  purchase variance                        where it is cheaper
 *   Cr  each tax code's input account            the recoverable tax it gives back
 *
 * The account that was credited when the bill was posted is debited here, and
 * every account the bill debited is credited, so the two entries net to nothing
 * account by account. The journal type is `debit_note`, which lets a report tell
 * a purchase correction apart from a credit note against a sale.
 *
 * A line for goods the company carries as stock is the part that needs care,
 * and it mirrors PostBill rather than reversing it. The bill never received the
 * stock — the receipt did, and the bill only cleared goods received not
 * invoiced — so a return does not touch goods received not invoiced either. The
 * goods leave the shelf instead: a stock issue at the price they came in at,
 * which debits the cost of sales account and credits inventory. The note then
 * credits that same cost account so the two cancel, leaving the net effect of
 * the line as a debit to the payable and a credit to inventory, with any
 * difference between the note's price and the receipt's price on the variance
 * account — exactly the shape PostBill gives a stock line, read the other way.
 *
 * The stock leaves out of the layers the original receipt opened, preferred
 * over whichever happen to be oldest, so a return costs what those particular
 * goods cost. That is the same mechanism a reversal uses, and it is what keeps
 * the inventory account in step with the layers: the value the issue credits
 * out of inventory is the value the layers give up.
 *
 * The plan is taken before the journal is built, because the entry needs the
 * value each line releases, and applied after it, because the movement has to
 * name the entry it answers. Both happen inside one transaction, so a debit
 * note whose stock cannot be sent back is refused whole rather than leaving the
 * ledger corrected and the shelf untouched.
 *
 * Posting is a one-way door: the note stops being editable, and a mistake is
 * corrected by reversing it rather than by an edit, which is why the guard
 * below refuses anything that is not a draft.
 */
final class PostDebitNote
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
        private readonly StockPolicy $policy,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
    ) {}

    public function __invoke(
        Bill $debitNote,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Bill {
        return DB::transaction(function () use ($debitNote, $userId, $idempotencyKey): Bill {
            $locked = Bill::query()
                ->whereKey($debitNote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->journal_id !== null) {
                throw BillStateException::alreadyPosted($locked);
            }

            if (! $locked->status->isEditable()) {
                throw BillStateException::notDraft($locked, $locked->status);
            }

            // Reaching here through the wrong route would otherwise let an
            // ordinary bill be posted a second time as a correction, so the
            // document type is checked as well as the state.
            if ($locked->document_type !== 'debit_note') {
                throw BillStateException::notADebitNote($locked);
            }

            $companyId = (int) $locked->company_id;
            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw BillStateException::withoutLines($locked);
            }

            if (! Money::of($locked->total)->isPositive()) {
                throw BillStateException::zeroValue($locked);
            }

            $supplier = Supplier::query()->whereKey($locked->supplier_id)->firstOrFail();

            // What each stock line will send back and at what value. Worked out
            // first because the journal has to be built from the value released
            // and must clear against the same figures the movement will use.
            $returns = $this->planReturns($locked, $lines, $companyId);

            $journal = ($this->create)(new JournalEntryData(
                companyId: $companyId,
                postingDate: $locked->bill_date->toDateString(),
                journalType: JournalType::DebitNote,
                lines: $this->journalLines($locked, $supplier, $lines, $companyId, $returns),
                reference: $locked->bill_no,
                memo: 'Debit note '.$locked->bill_no.' to '.$supplier->name,
                documentDate: $locked->bill_date->toDateString(),
                currencyCode: $locked->currency_code,
                exchangeRate: $locked->exchange_rate,
                sourceType: Bill::class,
                sourceId: (int) $locked->getKey(),
                createdByUserId: $userId,
            ));

            $posted = ($this->post)($journal, $idempotencyKey, $userId, $journal->row_version);

            // The goods go back out with the note. This runs after the journal
            // is posted but inside the same transaction, so a note whose stock
            // cannot be sent back is refused whole rather than leaving the
            // ledger corrected and the shelf unchanged.
            $this->applyReturns($locked, $returns, $companyId, $userId);

            $affected = Bill::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    // There is no approval gate on a debit note, so the state it
                    // reaches when it hits the ledger is the one that marks it
                    // as in force: approved, and open against the payable until
                    // a payment or a later note settles it.
                    'status' => BillStatus::Approved->value,
                    'journal_id' => $posted->getKey(),
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Bill %s', $locked->bill_no),
                    (int) $locked->row_version,
                );
            }

            $postedNote = Bill::query()->findOrFail($locked->getKey());

            $this->audit->record('debit_note.posted', $postedNote, [
                'status' => BillStatus::Draft->value,
            ], [
                'status' => BillStatus::Approved->value,
                'journal_no' => $posted->journal_no,
                'total' => $postedNote->total,
            ]);

            return $postedNote->load('lines');
        });
    }

    /**
     * The entry a debit note makes.
     *
     * Every line contributes its own net to the credit side, but by one of two
     * routes, mirroring PostBill. A line for something the company buys and does
     * not carry credits the expense account it named when the bill was posted,
     * undoing that debit.
     *
     * A line for an item the company carries as stock credits the item's cost of
     * sales account for the value the return releases, with any difference
     * between that and the note's own price on the variance account, so the line
     * still contributes its full net and the entry balances.
     *
     * @param  Collection<int, BillLine>  $lines
     * @param  array<int, array<string, mixed>>  $returns
     * @return array<int, JournalLineData>
     */
    private function journalLines(
        Bill $debitNote,
        Supplier $supplier,
        $lines,
        int $companyId,
        array $returns,
    ): array {
        $branchId = $debitNote->branch_id === null ? null : (int) $debitNote->branch_id;

        $byLine = [];

        foreach ($returns as $return) {
            $byLine[(int) $return['line']->getKey()] = $return;
        }

        // Resolved only when a stock line really is being returned, and once: a
        // note made up entirely of expenses has nothing to clear and a company
        // that has never configured a variance account can still post one.
        $company = null;
        $purchaseAccounts = null;

        $postings = [];

        foreach ($lines as $line) {
            $net = Money::of($line->line_subtotal);

            if (! $net->isPositive()) {
                continue;
            }

            $return = $byLine[(int) $line->getKey()] ?? null;

            if ($return !== null) {
                $company ??= Company::query()->findOrFail($companyId);
                $purchaseAccounts ??= $this->policy->purchaseAccounts($company);

                foreach ($this->releaseStockLine($line, $net, $return, $purchaseAccounts, $branchId) as $posting) {
                    $postings[] = $posting;
                }

                continue;
            }

            if ($line->expense_account_id === null) {
                throw BillStateException::lineWithoutExpenseAccount(
                    (string) $line->description
                );
            }

            $postings[] = new JournalLineData(
                accountId: (int) $line->expense_account_id,
                credit: $net,
                description: (string) $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                branchId: $branchId,
            );
        }

        // One posting per tax code, reversing the input tax the bill recovered,
        // so the VAT return reads the correction back code by code.
        foreach ($this->calculator->taxByCode($companyId, $lines, purchase: true) as $tax) {
            $postings[] = new JournalLineData(
                accountId: $tax['account_id'],
                credit: $tax['amount'],
                description: 'Input VAT '.$tax['tax_code'].' on debit note '.$debitNote->bill_no,
                taxCodeId: $tax['tax_code_id'],
                taxAmount: $tax['amount'],
                branchId: $branchId,
            );
        }

        $postings[] = new JournalLineData(
            accountId: (int) $supplier->payable_account_id,
            debit: Money::of($debitNote->total),
            description: 'Debit note '.$debitNote->bill_no,
            partyType: PartyType::Supplier,
            partyId: (int) $supplier->getKey(),
            branchId: $branchId,
        );

        return $postings;
    }

    /**
     * What one stock line of a debit note does: credits the cost account for
     * the value the return releases, and says what a price difference costs.
     *
     * The debit to the cost of sales account that the stock issue will make is
     * cancelled here by crediting the same account, so the pair leaves the line
     * as a debit to the payable and a credit to inventory. The difference
     * between the note's price and what the goods cost is a real gain or loss on
     * the return, and it lands on the variance account — a credit when the
     * supplier gives back more than the stock cost, a debit when less.
     *
     * @param  array<string, mixed>  $return
     * @param  array{grni: int, variance: int}  $accounts
     * @return array<int, JournalLineData>
     */
    private function releaseStockLine(
        BillLine $line,
        Money $net,
        array $return,
        array $accounts,
        ?int $branchId,
    ): array {
        $released = $return['released'];
        $cost = $return['cost'];

        $postings = [
            new JournalLineData(
                accountId: $cost,
                credit: $released,
                description: (string) $line->description,
                branchId: $branchId,
            ),
        ];

        // The goods cost one price and the note gives back another. What the
        // line is really worth is what the supplier is making good, so the
        // difference lands on the variance account and the credit side of the
        // line still equals its net.
        $variance = $net->minus($released);

        if (! $variance->isZero()) {
            $postings[] = new JournalLineData(
                accountId: $accounts['variance'],
                debit: $variance->isNegative() ? $variance->absolute() : Money::zero(),
                credit: $variance->isPositive() ? $variance : Money::zero(),
                description: 'Purchase variance on '.$line->description,
                branchId: $branchId,
            );
        }

        return $postings;
    }

    /**
     * Works out what each stock line will send back, without moving anything.
     *
     * The plan locks the layers it draws on, so it has to be taken inside this
     * transaction and applied before the transaction ends; keeping both halves
     * here is what stops the plan being forgotten or taken twice.
     *
     * @param  Collection<int, BillLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function planReturns(Bill $debitNote, $lines, int $companyId): array
    {
        $returns = [];

        foreach ($lines as $line) {
            $product = Product::stockItemFor(
                $companyId,
                $line->product_id === null ? null : (int) $line->product_id
            );

            if ($product === null) {
                continue;
            }

            $returns[] = $this->planLineReturn($line, $product, $companyId);
        }

        return $returns;
    }

    /**
     * One stock line's return, priced from the layers the original receipt
     * opened.
     *
     * @return array<string, mixed>
     */
    private function planLineReturn(BillLine $line, Product $product, int $companyId): array
    {
        $quantity = Quantity::of((string) $line->quantity);

        if (! Quantity::isPositive($quantity)) {
            throw StockStateException::nonPositiveQuantity('debit note line');
        }

        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->first();

        if ($warehouse === null) {
            throw StockStateException::noDefaultWarehouse();
        }

        $accounts = $this->recorder->accounts($product);

        // Preferred, not restricted, to the layers the receipt opened: a
        // delivery whose own stock has been issued and partly replaced is
        // answered over the stock that is really there.
        $plan = $this->layers->plan(
            companyId: $companyId,
            productId: (int) $product->getKey(),
            warehouseId: (int) $warehouse->getKey(),
            quantity: $quantity,
            preferLayerIds: $this->layersOpenedByReceipt($companyId, $line),
        );

        if ($plan->isShort() && ! $this->policy->allowsNegativeStock($this->recorder->company($companyId))) {
            throw StockStateException::negativeStockNotAllowed($plan->shortfall);
        }

        return [
            'line' => $line,
            'product' => $product,
            'warehouse' => $warehouse,
            'quantity' => $quantity,
            'plan' => $plan,
            'released' => $plan->totalCost(),
            'cost' => $accounts['cost'],
        ];
    }

    /**
     * The layers the receipt behind this line opened.
     *
     * The bill line and the receipt are joined through the GRNI allocation the
     * bill wrote when it cleared the delivery, so the return draws on exactly
     * the stock that line brought in rather than whichever layer is oldest.
     *
     * @return array<int, int>
     */
    private function layersOpenedByReceipt(int $companyId, BillLine $line): array
    {
        $originalLineId = $line->debit_note_for_line_id === null
            ? null
            : (int) $line->debit_note_for_line_id;

        if ($originalLineId === null) {
            return [];
        }

        $receiptIds = GrniAllocation::query()
            ->where('company_id', $companyId)
            ->where('bill_line_id', $originalLineId)
            ->pluck('receipt_movement_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        if ($receiptIds === []) {
            return [];
        }

        return StockLayer::query()
            ->where('company_id', $companyId)
            ->whereIn('receipt_movement_id', $receiptIds)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Sends the stock back out at the value the journal has already credited.
     *
     * Each line writes an issue at the planned cost and draws the layers down by
     * exactly the same value, which is what keeps the inventory account in step
     * with what the layers say is left.
     *
     * @param  array<int, array<string, mixed>>  $returns
     */
    private function applyReturns(Bill $debitNote, array $returns, int $companyId, ?int $userId): void
    {
        $date = $debitNote->bill_date->toDateString();
        $memo = 'Debit note '.$debitNote->bill_no;

        foreach ($returns as $return) {
            $product = $return['product'];
            $accounts = $this->recorder->accounts($product);

            $movement = $this->recorder->record(new StockMovementDraft(
                companyId: $companyId,
                product: $product,
                warehouse: $return['warehouse'],
                type: StockMovementType::Issue,
                movementDate: $date,
                // The sign is the direction: stock going out is written
                // negative, which is what makes the quantity on hand a single
                // SUM over the movement table and nothing else.
                quantity: Quantity::negate($return['quantity']),
                totalCost: $return['released'],
                debitAccountId: $accounts['cost'],
                creditAccountId: $accounts['inventory'],
                reference: $debitNote->bill_no,
                memo: $memo,
                userId: $userId,
                sourceType: BillLine::class,
                sourceId: (int) $return['line']->getKey(),
            ));

            $this->layers->apply($companyId, $movement, $return['plan'], $date);
        }
    }
}
