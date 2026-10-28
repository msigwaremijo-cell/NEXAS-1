<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `bank_statement_lines.status` column comment
 * ('unmatched|matched|ignored').
 *
 * A line starts unmatched because nothing has been done with it: the importer
 * only writes down what the bank said. Matched means it has been tied to a
 * journal line in the books, and the reconciliation engine is what does the
 * tying. Ignored is the deliberate exception: a line the reader has decided is
 * not part of the books at all, such as a line belonging to a different
 * account that a bank put on the same statement.
 */
enum BankStatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Matched => 'Matched',
            self::Ignored => 'Ignored',
        };
    }
}
