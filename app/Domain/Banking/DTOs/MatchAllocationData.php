<?php

namespace App\Domain\Banking\DTOs;

/**
 * One journal line a statement line is being matched to, and how much of the
 * movement that ledger entry accounts for.
 *
 * The amount is carried because a match need not be one for one. A single
 * movement on a statement is often several entries in the books: a receipt the
 * bank took its charges out of, a cheque that settled two invoices. Each of
 * those entries is its own allocation, and naming an amount rather than copying
 * the whole of the journal line is what makes a split match expressible — and
 * what lets the engine refuse a ledger entry that would be spent twice.
 */
final class MatchAllocationData
{
    public function __construct(
        public readonly int $journalLineId,
        public readonly string $amount,
    ) {}
}
