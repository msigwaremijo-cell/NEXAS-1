<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `journal_lines.party_type` column comment ('customer|supplier').
 * The matching id lives in `journal_lines.party_id`, which is intentionally
 * not a foreign key because it points at more than one table.
 */
enum PartyType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
        };
    }
}
