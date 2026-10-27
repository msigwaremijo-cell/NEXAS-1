<?php

namespace App\Domain\Accounting\DTOs;

use App\Domain\Accounting\Enums\JournalType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Everything CreateJournal needs to build one journal.
 *
 * fiscal_year_id and fiscal_period_id are deliberately absent: they are
 * derived from the posting date inside the action, so a caller cannot attach a
 * posting to a period that does not contain its date.
 *
 * A draft is allowed to be unbalanced on purpose — the balance rule is a
 * posting rule, enforced when the journal is posted, not when it is written.
 */
final class JournalEntryData
{
    /**
     * @param  array<int, JournalLineData>  $lines
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $postingDate,
        public readonly JournalType $journalType,
        public readonly array $lines,
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
        public readonly ?string $documentDate = null,
        public readonly ?string $currencyCode = null,
        public readonly string $exchangeRate = '1',
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
        public readonly ?int $reversesJournalId = null,
        public readonly ?int $createdByUserId = null,
    ) {
        if (count($this->lines) < 2) {
            throw new InvalidArgumentException(sprintf(
                'A journal needs at least two lines; %d given.',
                count($this->lines)
            ));
        }

        foreach ($this->lines as $line) {
            if (! $line instanceof JournalLineData) {
                throw new InvalidArgumentException(sprintf(
                    'Every journal line must be a JournalLineData instance, got %s.',
                    get_debug_type($line)
                ));
            }
        }
    }

    public function totalDebit(): Money
    {
        return Money::sum(array_map(
            static fn (JournalLineData $line): Money => $line->debit,
            array_values($this->lines)
        ));
    }

    public function totalCredit(): Money
    {
        return Money::sum(array_map(
            static fn (JournalLineData $line): Money => $line->credit,
            array_values($this->lines)
        ));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit()->equals($this->totalCredit());
    }
}
