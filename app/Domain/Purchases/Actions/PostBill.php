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
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\GrniLedger;
use App\Domain\Inventory\Services\StockPolicy;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts an approved bill to the ledger.
 *
 * The journal is the mirror of an invoice:
 *
 *   Dr  each expense line's account           the line net of discount
 *   Dr  goods received not invoiced           a stock line's receipts, at what they were booked at
 *   Dr  purchase variance                     where the bill is dearer than the receipt
 *   Cr  purchase variance                     where it is cheaper
 *   Dr  each tax code's input account         the recoverable tax on its lines
 *   Cr  supplier's payable control account    the bill total
 *
 * A line naming an item the company carries as stock is the exception, and it
 * is the rule Stage 8B exists for: **a bill for a stock item must not create a
 * second receipt.** The goods arrived already — the receipt moved the stock and
 * credited goods received not invoiced — so the bill settles that liability
 * rather than debiting an expense account. Nothing here writes a stock movement
 * or opens a layer; the oldest open receipts for the supplier and the product
 * are cleared, no further than what is still received and unbilled, and any
 * difference between what the delivery was booked at and what the bill says
 * lands on the purchase variance account.
 *
 * The approval gate is enforced here rather than in the controller, so no code
 * path — API, console command or job — can put an unapproved liability into the
 * payables balance. `journal_id` is the second guard: posting is not a status
 * change (an approved bill stays approved until it is paid), so the presence of
 * a journal is what marks it as done.
 */
final class PostBill
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
        private readonly StockPolicy $policy,
        private readonly GrniLedger $grni,
    ) {}

    public function __invoke(
        Bill $bill,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Bill {
        return DB::transaction(function () use ($bill, $userId, $idempotencyKey): Bill {
            $locked = Bill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->journal_id !== null) {
                throw BillStateException::alreadyPosted($locked);
            }

            if (! $locked->status->isPostable()) {
                throw BillStateException::notApproved($locked, $locked->status);
            }

            $companyId = (int) $locked->company_id;
            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw BillStateException::withoutLines($locked);
            }

            $total = Money::of($locked->total);

            if (! $total->isPositive()) {
                throw BillStateException::zeroValue($locked);
            }

            $supplier = Supplier::query()->whereKey($locked->supplier_id)->firstOrFail();

            $journal = ($this->create)(new JournalEntryData(
                companyId: $companyId,
                postingDate: $locked->bill_date->toDateString(),
                journalType: JournalType::Purchase,
                lines: $this->journalLines($locked, $supplier, $lines, $companyId),
                reference: $locked->bill_no,
                memo: 'Bill '.$locked->bill_no.' received from '.$supplier->name,
                documentDate: $locked->bill_date->toDateString(),
                currencyCode: $locked->currency_code,
                exchangeRate: $locked->exchange_rate,
                sourceType: Bill::class,
                sourceId: (int) $locked->getKey(),
                createdByUserId: $userId,
            ));

            $posted = ($this->post)($journal, $idempotencyKey, $userId, $journal->row_version);

            $affected = Bill::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
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

            $postedBill = Bill::query()->findOrFail($locked->getKey());

            $this->audit->record('bill.posted', $postedBill, [
                'journal_id' => null,
            ], [
                'journal_no' => $posted->journal_no,
                'status' => $postedBill->status->value,
                'total' => $postedBill->total,
            ]);

            return $postedBill->load('lines');
        });
    }

    /**
     * The entry a bill makes.
     *
     * Every line contributes its own net to the debit side, but by one of two
     * routes. A line for something the company buys and does not carry —
     * stationery, a service, a utility — debits the expense account it names.
     *
     * A line for an item the company carries as stock is the Stage 8B rule. The
     * goods arrived when they were received, so the bill settles what that
     * receipt credited instead of debiting an expense account, and it never
     * receives the stock a second time: it debits goods received not invoiced
     * for what the delivery was booked at, and any difference between that and
     * what the bill now says lands on the purchase variance account.
     *
     * @param  Collection<int, BillLine>  $lines
     * @return array<int, JournalLineData>
     */
    private function journalLines(
        Bill $bill,
        Supplier $supplier,
        $lines,
        int $companyId,
    ): array {
        // Resolved only when a line actually turns out to be stock, and once:
        // a bill made up entirely of expenses has nothing to clear, and a
        // company that has never configured a goods received account can still
        // post one.
        $company = null;
        $purchaseAccounts = null;

        $postings = [];

        foreach ($lines as $line) {
            $net = Money::of($line->line_subtotal);

            if (! $net->isPositive()) {
                continue;
            }

            $product = Product::stockItemFor($companyId, $line->product_id === null ? null : (int) $line->product_id);

            if ($product !== null) {
                $company ??= Company::query()->findOrFail($companyId);
                $purchaseAccounts ??= $this->policy->purchaseAccounts($company);

                foreach ($this->clearStockLine($bill, $supplier, $line, $net, $product, $companyId, $purchaseAccounts) as $posting) {
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
                debit: $net,
                description: (string) $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                branchId: $bill->branch_id === null ? null : (int) $bill->branch_id,
            );
        }

        // One posting per tax code rather than per account, so the line names
        // the tax it carries and the VAT return can read the input tax back
        // code by code even when two codes share the same control account.
        foreach ($this->calculator->taxByCode($companyId, $lines, purchase: true) as $tax) {
            $postings[] = new JournalLineData(
                accountId: $tax['account_id'],
                debit: $tax['amount'],
                description: 'Input VAT '.$tax['tax_code'].' on bill '.$bill->bill_no,
                taxCodeId: $tax['tax_code_id'],
                taxAmount: $tax['amount'],
                branchId: $bill->branch_id === null ? null : (int) $bill->branch_id,
            );
        }

        $postings[] = new JournalLineData(
            accountId: (int) $supplier->payable_account_id,
            credit: Money::of($bill->total),
            description: 'Bill '.$bill->bill_no,
            partyType: PartyType::Supplier,
            partyId: (int) $supplier->getKey(),
            branchId: $bill->branch_id === null ? null : (int) $bill->branch_id,
        );

        return $postings;
    }

    /**
     * What one stock line of a bill does: clears the oldest receipts this
     * supplier delivered for this item, and says what a price difference costs.
     *
     * Nothing here receives stock. The receipt moved it and opened the layer
     * that prices every later issue; a second receipt would count the quantity
     * twice and value the same units twice against the inventory account. The
     * plan is worked out and written down through the same ledger the receipts
     * use, so what this bill cleared is a fact the next bill reads rather than
     * a figure it recomputes.
     *
     * A bill for goods that were never received, or for more than is still
     * received and unbilled, is refused rather than answered with a receipt.
     *
     * @param  array{grni: int, variance: int}  $accounts
     * @return array<int, JournalLineData>
     */
    private function clearStockLine(
        Bill $bill,
        Supplier $supplier,
        BillLine $line,
        Money $net,
        Product $product,
        int $companyId,
        array $accounts,
    ): array {
        $wanted = Quantity::of((string) $line->quantity);

        if (! Quantity::isPositive($wanted)) {
            throw StockStateException::nonPositiveQuantity('bill line');
        }

        $plan = $this->grni->plan(
            companyId: $companyId,
            supplierId: (int) $supplier->getKey(),
            productId: (int) $product->getKey(),
            quantity: $wanted,
        );

        if ($plan->isEmpty()) {
            throw StockStateException::noOpenReceipt($product, $supplier);
        }

        if ($plan->isShort()) {
            throw StockStateException::insufficientOpenReceipt(
                $product,
                $supplier,
                $wanted,
                $plan->clearedQuantity(),
            );
        }

        // Written down before the entry is built, so what this line cleared is
        // recorded even as the posting below is assembled from it.
        $this->grni->apply($companyId, (int) $line->getKey(), $plan);

        $branchId = $bill->branch_id === null ? null : (int) $bill->branch_id;
        $cleared = $plan->value();

        $postings = [
            new JournalLineData(
                accountId: $accounts['grni'],
                debit: $cleared,
                description: (string) $line->description,
                branchId: $branchId,
            ),
        ];

        // The delivery was booked at one price and the bill says another. What
        // the line is really worth is what the bill says, so the difference
        // lands on the variance account: a debit when the goods arrived dearer
        // than they were billed, a credit when the bill is the dearer of the
        // two. Posting it keeps the debit side of the line equal to its net,
        // which is what the header total is built from.
        $variance = $net->minus($cleared);

        if (! $variance->isZero()) {
            $postings[] = new JournalLineData(
                accountId: $accounts['variance'],
                debit: $variance->isPositive() ? $variance : Money::zero(),
                credit: $variance->isNegative() ? $variance->absolute() : Money::zero(),
                description: 'Purchase variance on '.$line->description,
                branchId: $branchId,
            );
        }

        return $postings;
    }
}
