<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `bank_accounts.status` column ('active|inactive|closed').
 *
 * The column carries no MySQL CHECK, so this enum is the only thing between a
 * typo and a money account nobody can find: an Inactive account keeps its
 * history but must not be handed a new receipt or payment, and a Closed one is
 * finished with entirely.
 */
enum BankAccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Closed = 'closed';

    /**
     * Whether money may still move through this account.
     */
    public function acceptsNewEntries(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Closed => 'Closed',
        };
    }
}
