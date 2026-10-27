<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\JournalStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a posted journal the only way a ledger allows: by posting a second,
 * mirror-image journal rather than editing the first one.
 *
 * The reversal is created as a draft and then posted through PostJournal, so
 * it has to be balanced and land in an open period like any other entry — and
 * it takes its own number from the same gapless sequence.
 *
 * Two links are then final, and only once:
 *   - the reversal carries reverses_journal_id (a unique column, so a journal
 *     can be reversed at most once);
 *   - the original moves posted -> reversed and carries reversed_by_journal_id.
 * That second move is the single transition trg_journals_before_update permits
 * on an otherwise immutable header.
 */
final class ReverseJournal
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        Journal $journal,
        ?string $postingDate = null,
        ?string $memo = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Journal {
        return DB::transaction(function () use (
            $journal,
            $postingDate,
            $memo,
            $userId,
            $idempotencyKey
        ): Journal {
            $original = Journal::query()
                ->whereKey($journal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $original->isPosted()) {
                throw JournalStateException::notPosted($original);
            }

            $reversal = ($this->create)(new JournalEntryData(
                companyId: (int) $original->company_id,
                postingDate: $postingDate ?? $original->posting_date->toDateString(),
                journalType: JournalType::Reversal,
                lines: $this->mirrorLines($original),
                reference: $original->reference,
                memo: $memo ?? 'Reversal of '.$original->journal_no,
                documentDate: $original->document_date?->toDateString(),
                currencyCode: $original->currency_code,
                exchangeRate: $original->exchange_rate,
                sourceType: $original->source_type,
                sourceId: $original->source_id,
                reversesJournalId: $original->getKey(),
                createdByUserId: $userId,
            ));

            $posted = ($this->post)($reversal, $idempotencyKey, $userId, $reversal->row_version);

            $affected = Journal::query()
                ->whereKey($original->getKey())
                ->where('row_version', $original->row_version)
                ->update([
                    'status' => JournalStatus::Reversed->value,
                    'reversed_by_journal_id' => $posted->getKey(),
                    'row_version' => $original->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::journal($original);
            }

            $this->audit->record('journal.reversed', $original, [
                'status' => JournalStatus::Posted->value,
            ], [
                'status' => JournalStatus::Reversed->value,
                'reversed_by_journal_id' => $posted->getKey(),
                'reversal_journal_no' => $posted->journal_no,
            ]);

            return $posted;
        });
    }

    /**
     * The same accounts and amounts with both sides swapped, so the two
     * journals net to zero per account.
     *
     * @return array<int, JournalLineData>
     */
    private function mirrorLines(Journal $original): array
    {
        return $original->lines()
            ->get()
            ->map(static fn (JournalLine $line): JournalLineData => new JournalLineData(
                accountId: (int) $line->account_id,
                debit: $line->credit,
                credit: $line->debit,
                description: $line->description,
                currencyCode: $line->currency_code,
                exchangeRate: $line->exchange_rate,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                taxAmount: $line->tax_amount,
                partyType: $line->party_type,
                partyId: $line->party_id === null ? null : (int) $line->party_id,
                branchId: $line->branch_id === null ? null : (int) $line->branch_id,
            ))
            ->all();
    }
}
