<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `bank_statement_lines.status` column comment
 * ('unmatched|matched|ignored').
 */
enum BankStatementLineStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Ignored = 'ignored';

    /**
     * A matched line is locked: it may not be re-matched until it is unmatched
     * again.
     */
    public function isMatched(): bool
    {
        return $this === self::Matched;
    }

    public function isAvailable(): bool
    {
        return $this === self::Unmatched;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Unmatched',
            self::Matched => 'Matched',
            self::Ignored => 'Ignored',
        };
    }
}
