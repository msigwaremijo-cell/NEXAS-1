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
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts an approved bill to the ledger.
 *
 * The journal is the mirror of an invoice:
 *
 *   Dr  each line's expense account          the line net of discount
 *   Dr  each tax code's input account        the recoverable tax on its lines
 *   Cr  supplier's payable control account   the bill total
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
                throw ConcurrentModificationException::journal($locked);
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
     * @param  Collection<int, BillLine>  $lines
     * @return array<int, JournalLineData>
     */
    private function journalLines(
        Bill $bill,
        Supplier $supplier,
        $lines,
        int $companyId,
    ): array {
        $postings = [];

        foreach ($lines as $line) {
            $net = Money::of($line->line_subtotal);

            if (! $net->isPositive()) {
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

        foreach ($this->calculator->taxByAccount($companyId, $lines, purchase: true) as $accountId => $tax) {
            $postings[] = new JournalLineData(
                accountId: $accountId,
                debit: $tax,
                description: 'Input VAT on bill '.$bill->bill_no,
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
}
