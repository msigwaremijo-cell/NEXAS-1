<?php

namespace App\Domain\Banking\DTOs;

use App\Domain\Banking\Enums\MatchType;
use App\Support\Money;

/**
 * Everything MatchStatementLine needs to tie one statement line to the books.
 *
 * The statement line is named by its id rather than by its ULID because this is
 * the engine behind a screen that has just read the line list: the ids come
 * back from that list, and a match is recorded against the row the reader was
 * looking at.
 *
 * matchType is carried rather than assumed because the database records how a
 * match was made. A hand match and an automatic one are the same row with the
 * same rules behind them, and the only thing telling a reviewer which is which
 * is this column.
 */
final class MatchData
{
    /**
     * @param  list<MatchAllocationData>  $allocations
     */
    public function __construct(
        public readonly int $companyId,
        public readonly int $bankAccountId,
        public readonly int $statementLineId,
        public readonly array $allocations,
        public readonly MatchType $matchType = MatchType::Manual,
        public readonly ?int $userId = null,
    ) {}

    /**
     * What the allocations add up to, which is how much of the statement line's
     * movement this match accounts for.
     */
    public function matchedTotal(): Money
    {
        return Money::sum(array_map(
            static fn (MatchAllocationData $allocation): Money => Money::of($allocation->amount),
            $this->allocations
        ));
    }
}
