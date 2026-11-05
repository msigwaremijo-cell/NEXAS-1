<?php

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Inventory\Actions\ReverseStockMovement;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Takes a posted debit note back out of the books the only way a ledger allows:
 * by posting the opposite entry, never by editing or deleting the note.
 *
 * A debit note is two corrections at once — an entry that reduces the payable,
 * and, where it was for stock, an issue that sends the goods back. Undoing it
 * therefore undoes both, in the two different ways each of them allows:
 *
 * The stock is put back on the shelf first, through the stock module's own
 * reversal. Each issue the note wrote is answered by a movement of its own that
 * opens a fresh layer at the price the goods left at — a layer's remaining
 * quantity is only ever written downwards, so the value comes back into a new
 * layer rather than into the one the issue drew on. That also posts the mirror
 * of the issue's own entry, so the inventory account is restored to what it was.
 *
 * The note's entry is then reversed through the same ReverseJournal the rest of
 * the books use: the journal is mirrored and marked reversed, so the payable
 * and the cost the note moved net to nothing account by account.
 *
 * A reversal is final, and that is the whole of the guard. Once a note is
 * reversed it is refused — reversing the reversal would put the correction back
 * into the ledger, which is an edit of history by another name — and a draft or
 * void note is refused too, because neither has an entry in the books to take
 * back out. This mirrors the stock module, which refuses to reverse a movement
 * that is itself a reversal.
 *
 * The note keeps its row, its number and the entry it posted; it is marked
 * reversed and nothing else about it changes. What was true before is still
 * readable afterwards, which is the point of never removing a posted document.
 */
final class ReverseDebitNote
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly ReverseStockMovement $reverseMovement,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        Bill $debitNote,
        ?string $postingDate = null,
        ?string $memo = null,
        ?int $userId = null,
    ): Bill {
        return DB::transaction(function () use ($debitNote, $postingDate, $memo, $userId): Bill {
            $locked = Bill::query()
                ->whereKey($debitNote->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->document_type !== 'debit_note') {
                throw BillStateException::notADebitNote($locked);
            }

            // A reversal is the last word: the note it answers has already been
            // taken out of the ledger, and reversing the reversal would put it
            // back. Refused here rather than left to the journal's own rule so
            // the document and the books never disagree about what happened.
            if ($locked->status === BillStatus::Reversed) {
                throw BillStateException::alreadyReversed($locked);
            }

            if ($locked->journal_id === null) {
                throw BillStateException::notPostedYet($locked);
            }

            if (! $locked->status->isOpen()) {
                throw BillStateException::notReversible($locked, $locked->status);
            }

            $companyId = (int) $locked->company_id;
            $date = $postingDate ?? $locked->bill_date->toDateString();
            $memo ??= 'Reversal of debit note '.$locked->bill_no;
            $from = $locked->status->value;

            // The goods go back before the entry that valued them is removed,
            // so the shelf is never holding stock the inventory account does
            // not, even for the length of one statement inside the transaction.
            $this->restoreStock($locked, $companyId, $date, $memo, $userId);

            $reversal = ($this->reverseJournal)(
                $locked->journal,
                $date,
                $memo,
                $userId,
            );

            $affected = Bill::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    'status' => BillStatus::Reversed->value,
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Bill %s', $locked->bill_no),
                    (int) $locked->row_version,
                );
            }

            $this->audit->record('debit_note.reversed', $locked, [
                'status' => $from,
            ], [
                'status' => BillStatus::Reversed->value,
                'reversal_journal_no' => $reversal->journal_no,
            ]);

            return Bill::query()->findOrFail($locked->getKey())->load('lines');
        });
    }

    /**
     * Puts back the stock the note sent out, movement by movement.
     *
     * The issues are found through the lines they came from rather than by date
     * or reference, so exactly the movements this note wrote are answered and
     * nothing else on the same day is caught up with them. A note for something
     * the company buys and does not carry wrote no movement at all, so the loop
     * has nothing to do and the reversal is simply the journal.
     */
    private function restoreStock(
        Bill $debitNote,
        int $companyId,
        string $date,
        string $memo,
        ?int $userId,
    ): void {
        $lineIds = $debitNote->lines()
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();

        if ($lineIds === []) {
            return;
        }

        $movements = StockMovement::query()
            ->where('company_id', $companyId)
            ->where('source_type', BillLine::class)
            ->whereIn('source_id', $lineIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($movements as $movement) {
            ($this->reverseMovement)(
                companyId: $companyId,
                movementId: (int) $movement->getKey(),
                movementDate: $date,
                memo: $memo,
                userId: $userId,
            );
        }
    }
}
