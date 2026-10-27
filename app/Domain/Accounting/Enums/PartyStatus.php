<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `customers.status` and `suppliers.status` column comments
 * ('active|inactive|blocked').
 *
 * One enum serves both sides of the ledger because the lifecycle is the same
 * for a customer and a supplier: Active trades normally, Inactive is dormant
 * but keeps its history, Blocked must not be given a new document until an
 * Owner lifts it.
 */
enum PartyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    /**
     * Only an active party may be put on a new invoice, bill or receipt.
     */
    public function acceptsNewDocuments(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Blocked => 'Blocked',
        };
    }
}
