<?php

namespace App\Domain\Accounting\Exceptions;

use App\Models\Journal;

/**
 * The optimistic lock did not match, so somebody else moved the row between
 * the caller reading it and the caller writing it. Nothing is written.
 */
final class ConcurrentModificationException extends AccountingException
{
    public static function journal(Journal $journal): self
    {
        return new self(sprintf(
            'Journal %s was changed by another transaction (expected row_version %d). '
            .'Reload it and try again.',
            $journal->journal_no,
            $journal->row_version
        ));
    }

    /**
     * The same conflict on a row that is not a journal, so master data can use
     * the row_version guard too without inventing a second exception type.
     */
    public static function row(string $document, int $expected): self
    {
        return new self(sprintf(
            '%s was changed by another transaction (expected row_version %d). '
            .'Reload it and try again.',
            $document,
            $expected
        ));
    }
}
