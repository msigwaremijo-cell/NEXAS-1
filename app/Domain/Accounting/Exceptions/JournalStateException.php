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
}
