<?php

namespace App\Domain\Accounting\Enums;

/**
 * Which side of the ledger an account carries a positive balance on.
 * Mirrors the `normal_balance` column comment ('debit|credit').
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    /**
     * +1 when the account grows on the debit side, -1 when it grows on credit.
     * Used when turning a debit/credit pair into a single signed amount.
     */
    public function sign(): int
    {
        return $this === self::Debit ? 1 : -1;
    }

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Credit => 'Credit',
        };
    }
}
