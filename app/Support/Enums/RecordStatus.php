<?php

namespace App\Support\Enums;

/**
 * Status for master data rows whose column comment reads
 * ('active|inactive|blocked'), such as customers and suppliers.
 *
 * "Blocked" is stricter than "inactive": an inactive party is simply not
 * offered for new documents, a blocked party must not be transacted with.
 */
enum RecordStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether new documents may reference this record.
     */
    public function isTransactable(): bool
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
