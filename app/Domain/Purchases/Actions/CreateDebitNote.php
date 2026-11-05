<?php

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentLineCalculator;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft supplier debit note against a posted bill.
 *
 * A debit note is the purchase side of a credit note: where a credit note gives
 * a customer money back, a debit note takes money back from a supplier — for
 * goods returned, an overcharge, or a bill that was simply too high. It is not
 * a bill with a minus sign: every line gives back part or all of one line of
 * the bill it corrects, and that is why a line may only be asked for by naming
 * the original bill line and a quantity. The price, the discount, the tax code
 * and the expense account all come from the original line rather than from the
 * caller, so a client that could name its own price could debit a purchase at a
 * figure the supplier never charged.
 *
 * The ceiling is monetary, and it is the bill's own open amount. A debit note
 * may only cancel a liability that is really there, so the note's total is
 * checked against what the bill still owes once its payments and any earlier
 * debit notes are taken off. Unlike a credit note — whose guard is a quantity
 * against the line it answers — the rule here is the money still outstanding,
 * which is what makes "never send the payable below zero" true by construction.
 *
 * Nothing here reaches the ledger: the document is a draft, and posting it is a
 * separate action with its own permission. A draft bill is corrected by editing
 * it, so only a bill that has been posted can be answered at all.
 */
final class CreateDebitNote
{
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumbering $numbering,
        private readonly DocumentLineCalculator $calculator,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  bill_date, reference, notes, lines
     */
    public function __invoke(int $companyId, Bill $bill, array $attributes, ?int $userId = null): Bill
    {
        return DB::transaction(function () use ($companyId, $bill, $attributes, $userId): Bill {
            $target = Bill::query()
                ->where('company_id', $companyId)
                ->whereKey($bill->getKey())
                ->firstOrFail();

            if ($target->document_type !== 'bill') {
                throw BillStateException::notABill($target);
            }

            // A draft has not reached the ledger, so there is no liability to
            // correct. Answering it is a matter of editing it, not debiting it.
            if ($target->journal_id === null) {
                throw BillStateException::notPostedYet($target);
            }

            if ($target->status === BillStatus::Void) {
                throw BillStateException::isVoid($target);
            }

            $requested = $attributes['lines'] ?? [];

            if ($requested === []) {
                throw new BillStateException('A debit note needs at least one line before it can be created.');
            }

            $billDate = (string) ($attributes['bill_date'] ?? $target->bill_date->toDateString());
            $period = $this->calendar->resolve($companyId, $billDate);

            $originalLines = $target->lines()->get()->keyBy(
                static fn (BillLine $line): int => (int) $line->getKey()
            );

            $debitLines = [];
            $links = [];

            foreach ($requested as $entry) {
                $lineId = (int) ($entry['bill_line_id'] ?? 0);
                $original = $originalLines->get($lineId);

                if ($original === null) {
                    throw BillStateException::unknownLine($target, $lineId);
                }

                $quantity = Quantity::of((string) $entry['quantity']);

                if (! Quantity::isPositive($quantity)) {
                    throw BillStateException::nonPositiveDebit((string) $original->description);
                }

                // Every figure but the quantity is the original line's, so the
                // debit is priced exactly as the purchase was. The discount is
                // carried as a rate rather than an amount so a part-debit takes
                // its share of the discount rather than all of it.
                $debitLines[] = [
                    'description' => (string) $original->description,
                    'quantity' => $quantity,
                    'unit_price' => (string) $original->unit_price,
                    'discount_rate' => (string) $original->discount_rate,
                    'tax_code_id' => $original->tax_code_id === null ? null : (int) $original->tax_code_id,
                    'product_id' => $original->product_id === null ? null : (int) $original->product_id,
                    'account_id' => $original->expense_account_id === null
                        ? null
                        : (int) $original->expense_account_id,
                ];

                $links[] = $lineId;
            }

            $computed = $this->calculator->compute($companyId, $billDate, $debitLines);

            // The ceiling: what the bill still owes, less what earlier debit
            // notes have already taken off it. Checked on the note's total
            // rather than on each line, because the money still open is a
            // property of the bill as a whole.
            $available = $this->openAmount($companyId, $target);

            if ($computed->total->greaterThan($available)) {
                throw BillStateException::overDebit(
                    $target,
                    $computed->total->toDecimal(),
                    $available->toDecimal(),
                );
            }

            $debitNote = new Bill([
                'company_id' => $companyId,
                'supplier_id' => (int) $target->supplier_id,
                'branch_id' => $target->branch_id,
                'bill_no' => $this->numbering->next(
                    $companyId,
                    DocumentType::DebitNote,
                    $period->fiscal_year_id
                ),
                'document_type' => 'debit_note',
                'debit_note_for_bill_id' => (int) $target->getKey(),
                'status' => BillStatus::Draft->value,
                'bill_date' => $billDate,
                // A debit note is not a debt with terms: it reduces what is
                // owed the moment it is posted, so it falls due on its own date.
                'due_date' => $billDate,
                'currency_code' => $target->currency_code,
                'exchange_rate' => $target->exchange_rate,
                'reference' => $attributes['reference'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'approval_status' => 'not_required',
                'created_by_user_id' => $userId,
            ]);

            // Totals are absent from the fillable list, so they are written
            // directly from the calculator rather than mass-assigned.
            $debitNote->subtotal = $computed->subtotal->toDecimal();
            $debitNote->discount_total = $computed->discountTotal->toDecimal();
            $debitNote->tax_total = $computed->taxTotal->toDecimal();
            $debitNote->total = $computed->total->toDecimal();
            $debitNote->amount_paid = '0.0000';

            $debitNote->save();

            // compute() prices the lines in the order they were handed over, so
            // the links line up with the priced lines by position.
            foreach (array_values($computed->lines) as $index => $priced) {
                $line = new BillLine([
                    'company_id' => $companyId,
                    'bill_id' => $debitNote->getKey(),
                    'line_no' => $priced['line_no'],
                    'product_id' => $priced['product_id'],
                    'description' => $priced['description'],
                    'quantity' => $priced['quantity'],
                    'unit_price' => $priced['unit_price'],
                    'discount_rate' => $priced['discount_rate'],
                    'tax_code_id' => $priced['tax_code_id'],
                    'expense_account_id' => $priced['account_id'],
                    'debit_note_for_line_id' => $links[$index],
                ]);

                $line->discount_amount = $priced['discount_amount'];
                $line->tax_rate = $priced['tax_rate'];
                $line->tax_amount = $priced['tax_amount'];
                $line->line_subtotal = $priced['line_subtotal'];
                $line->line_total = $priced['line_total'];

                $line->save();
            }

            $this->audit->record('debit_note.created', $debitNote, null, [
                'bill_no' => $debitNote->bill_no,
                'corrects_bill' => $target->bill_no,
                'supplier_id' => (int) $target->supplier_id,
                'total' => $debitNote->total,
                'line_count' => count($computed->lines),
            ]);

            return $debitNote->load('lines');
        });
    }

    /**
     * What the bill still owes: its own balance less every earlier debit note.
     *
     * Only live notes count. A voided note took nothing off the payable, and a
     * reversed one has had what it took put back, so neither must eat into what
     * is left to debit. Drafts do count, because a draft is a claim on the same
     * open amount and two drafts that together exceed it must be refused even
     * before either is posted.
     */
    private function openAmount(int $companyId, Bill $bill): Money
    {
        $open = Money::of($bill->total)->minus(Money::of($bill->amount_paid));

        $debited = Bill::query()
            ->where('company_id', $companyId)
            ->where('document_type', 'debit_note')
            ->where('debit_note_for_bill_id', $bill->getKey())
            ->whereNotIn('status', [BillStatus::Void->value, BillStatus::Reversed->value])
            ->sum('total');

        return $open->minus(Money::of((string) $debited));
    }
}
