<?php

namespace App\Domain\Accounting\Enums;

/**
 * Shared by `fiscal_years.status` and `fiscal_periods.status`, both documented
 * as ('open|closed|locked').
 */
enum FiscalStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Locked = 'locked';

    /**
     * Only an open period accepts new postings. Closed stops normal entry but
     * can still be reopened; locked is final.
     */
    public function acceptsPostings(): bool
    {
        return $this === self::Open;
    }

    public function canBeReopened(): bool
    {
        return $this === self::Closed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Locked => 'Locked',
        };
    }
}
