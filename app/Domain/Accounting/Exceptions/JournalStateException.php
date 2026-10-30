<?php

namespace App\Domain\Accounting\Exceptions;

use App\Models\Journal;

/**
 * The journal exists but is not in the state the caller assumed, so the move
 * they asked for would rewrite history rather than record it.
 */
final class JournalStateException extends AccountingException
{
    public static function notDraft(Journal $journal): self
    {
        return new self(sprintf(
            'Journal %s is %s, not a draft, so it cannot be posted.',
            $journal->journal_no,
            $journal->status?->value ?? 'unknown'
        ));
    }

    public static function notPosted(Journal $journal): self
    {
        return new self(sprintf(
            'Journal %s is %s, so it cannot be reversed. Only a posted journal can be reversed.',
            $journal->journal_no,
            $journal->status?->value ?? 'unknown'
        ));
    }

    /**
     * The journal is itself a reversal. A reversal is the last word on an
     * entry, so reversing one would put the entry it answers back into the
     * books; the move is refused rather than allowed to undo history twice.
     */
    public static function reversalCannotBeReversed(Journal $journal): self
    {
        return new self(sprintf(
            'Journal %s is itself a reversal, and a reversal cannot be reversed.',
            $journal->journal_no
        ));
    }
}
