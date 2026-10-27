<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Exceptions\JournalStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Models\IdempotencyKey;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Support\Enums\IdempotencyStatus;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a draft journal to posted, once and only once.
 *
 * The order inside the transaction is deliberate:
 *
 *   1. claim the idempotency key, if one was supplied, and replay a completed
 *      response instead of posting a second time;
 *   2. lock the journal row with SELECT ... FOR UPDATE, so two callers cannot
 *      both read "draft" and both post;
 *   3. re-read the lines and check the balance in the domain, so an unbalanced
 *      journal fails with a sentence rather than SQLSTATE 45000 (the trigger
 *      remains the backstop);
 *   4. lock the fiscal period and confirm it and its year are open, which is
 *      what stops a period close from interleaving with a posting;
 *   5. update guarded by row_version, so a caller holding a stale copy is
 *      refused rather than allowed to overwrite somebody else's work.
 *
 * total_debit, total_credit and posted_at are never sent: the BEFORE UPDATE
 * trigger derives them from the lines, so no caller gets to assert its own
 * totals.
 */
final class PostJournal
{
    private const SCOPE = 'journal.post';

    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        Journal $journal,
        ?string $idempotencyKey = null,
        ?int $postedByUserId = null,
        ?int $expectedRowVersion = null,
    ): Journal {
        return DB::transaction(function () use (
            $journal,
            $idempotencyKey,
            $postedByUserId,
            $expectedRowVersion
        ): Journal {
            $record = $idempotencyKey === null
                ? null
                : $this->claimKey($journal, $idempotencyKey);

            if ($record !== null && $record->status->isReplayable()) {
                return Journal::query()->findOrFail($record->response_id);
            }

            $locked = Journal::query()
                ->whereKey($journal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isDraft()) {
                if (! $locked->isPosted()) {
                    throw JournalStateException::notDraft($locked);
                }

                // Already posted: a retry is a no-op, not a second posting.
                $this->completeKey($record, $locked);

                return $locked;
            }

            $this->assertBalanced($locked);

            $this->calendar->lockOpen($locked->fiscalPeriod()->firstOrFail());

            $expected = $expectedRowVersion ?? $locked->row_version;

            $affected = Journal::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $expected)
                ->update([
                    'status' => JournalStatus::Posted->value,
                    'posted_by_user_id' => $postedByUserId,
                    'idempotency_key' => $idempotencyKey,
                    'row_version' => $expected + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::journal($locked);
            }

            $posted = Journal::query()->findOrFail($locked->getKey());

            $this->audit->record('journal.posted', $posted, [
                'status' => JournalStatus::Draft->value,
            ], [
                'status' => JournalStatus::Posted->value,
                'total_debit' => $posted->total_debit,
                'total_credit' => $posted->total_credit,
            ]);

            $this->completeKey($record, $posted);

            return $posted->load('lines');
        });
    }

    /**
     * The balance is recomputed from the stored lines rather than trusted from
     * the caller, so a journal that was edited by someone else is judged on
     * what is actually in the database.
     */
    private function assertBalanced(Journal $journal): void
    {
        $lines = JournalLine::query()
            ->where('journal_id', $journal->getKey())
            ->orderBy('line_no')
            ->get();

        if ($lines->count() < 2) {
            throw InvalidJournalException::tooFewLines($lines->count());
        }

        $debit = Money::sum($lines->map(
            static fn (JournalLine $line): Money => Money::of($line->debit)
        )->all());

        $credit = Money::sum($lines->map(
            static fn (JournalLine $line): Money => Money::of($line->credit)
        )->all());

        if (! $debit->isPositive() || ! $credit->isPositive()) {
            throw InvalidJournalException::zeroValue();
        }

        if (! $debit->equals($credit)) {
            throw InvalidJournalException::unbalanced($debit, $credit);
        }
    }

    /**
     * The request fingerprint is built from the journal's own key, so a retry
     * of the same posting always produces the same hash even though the
     * journal's row_version moves when it succeeds.
     */
    private function claimKey(Journal $journal, string $key): IdempotencyKey
    {
        $companyId = (int) $journal->company_id;
        $requestHash = hash('sha256', self::SCOPE.'|'.$journal->getKey());

        $existing = IdempotencyKey::query()
            ->where('company_id', $companyId)
            ->where('scope', self::SCOPE)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            try {
                return IdempotencyKey::create([
                    'company_id' => $companyId,
                    'scope' => self::SCOPE,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'status' => IdempotencyStatus::InProgress,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw IdempotencyConflictException::inProgress($key);
            }
        }

        if ($existing->request_hash !== $requestHash) {
            throw IdempotencyConflictException::payloadMismatch($key);
        }

        if ($existing->status === IdempotencyStatus::InProgress) {
            throw IdempotencyConflictException::inProgress($key);
        }

        if ($existing->status === IdempotencyStatus::Failed) {
            $existing->forceFill([
                'status' => IdempotencyStatus::InProgress,
                'completed_at' => null,
            ])->save();
        }

        return $existing;
    }

    private function completeKey(?IdempotencyKey $record, Journal $journal): void
    {
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => IdempotencyStatus::Completed,
            'response_type' => Journal::class,
            'response_id' => $journal->getKey(),
            'response_status' => 201,
            'response_body' => [
                'journal_no' => $journal->journal_no,
                'status' => $journal->status->value,
                'total_debit' => $journal->total_debit,
                'total_credit' => $journal->total_credit,
            ],
            'completed_at' => now(),
        ])->save();
    }
}
