<?php

namespace App\Domain\Assets\Enums;

/**
 * Where an asset stands between being entered and being written off.
 *
 * The moves are one-way: draft -> in_service -> disposed. There is no way back
 * except by reversing the journal a move wrote, which is a controlled
 * correction rather than an edit — a disposal that is undone is undone by the
 * opposite entry, not by quietly putting the row back the way it was.
 *
 * A draft has not been placed in service, so it has no date to depreciate from
 * and is never charged. Only an asset in service depreciates.
 */
enum AssetStatus: string
{
    case Draft = 'draft';
    case InService = 'in_service';
    case Disposed = 'disposed';

    /**
     * Only an asset in service is depreciated. A draft has no in-service date
     * to count months from, and a disposed asset has stopped.
     */
    public function isDepreciable(): bool
    {
        return $this === self::InService;
    }

    /**
     * Whether a gain or loss may be booked against it. An asset is disposed of
     * once, and only from service: a draft has never reached the books, so
     * there is nothing in them to take out. Disposing a draft is refused
     * rather than posting an entry that credits an account nothing was ever
     * debited to.
     */
    public function isDisposable(): bool
    {
        return $this === self::InService;
    }

    public function isDisposed(): bool
    {
        return $this === self::Disposed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InService => 'In service',
            self::Disposed => 'Disposed',
        };
    }
}
