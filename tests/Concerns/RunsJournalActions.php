<?php

namespace Tests\Concerns;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Models\Journal;

/**
 * Drives the journal actions over the raw fixtures built by
 * BuildsAccountingFixtures.
 *
 * The fixtures stay raw SQL on purpose: these are the same company, fiscal
 * year, period and accounts the database-level integrity tests use, so the
 * actions are exercised against exactly the schema the triggers protect.
 */
trait RunsJournalActions
{
    use BuildsAccountingFixtures;

    /**
     * @param  array<int, JournalLineData>  $lines
     */
    protected function entry(
        array $lines,
        ?string $postingDate = null,
        ?string $memo = null,
        ?string $reference = null,
        ?string $currencyCode = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        JournalType $journalType = JournalType::General,
    ): JournalEntryData {
        return new JournalEntryData(
            companyId: $this->companyId,
            postingDate: $postingDate ?? $this->postingDate,
            journalType: $journalType,
            lines: $lines,
            reference: $reference,
            memo: $memo,
            currencyCode: $currencyCode,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );
    }

    /**
     * Cash in, revenue out, for the same amount.
     *
     * @return array<int, JournalLineData>
     */
    protected function balancedLines(string $amount = '250.0000'): array
    {
        return [
            JournalLineData::debit($this->cashAccountId, $amount, 'Cash received'),
            JournalLineData::credit($this->revenueAccountId, $amount, 'Sales revenue'),
        ];
    }

    protected function createViaAction(JournalEntryData $entry): Journal
    {
        return app(CreateJournal::class)($entry);
    }

    protected function postViaAction(
        Journal $journal,
        ?string $idempotencyKey = null,
        ?int $expectedRowVersion = null,
    ): Journal {
        return app(PostJournal::class)($journal, $idempotencyKey, null, $expectedRowVersion);
    }

    protected function reverseViaAction(
        Journal $journal,
        ?string $idempotencyKey = null,
        ?string $postingDate = null,
    ): Journal {
        return app(ReverseJournal::class)($journal, $postingDate, null, null, $idempotencyKey);
    }
}
