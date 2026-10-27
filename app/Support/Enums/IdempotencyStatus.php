<?php

namespace App\Support\Enums;

/**
 * Mirrors the `idempotency_keys.status` column comment
 * ('in_progress|completed|failed').
 */
enum IdempotencyStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Only a completed key may be replayed with its stored response. A failed
     * key is released so the caller can retry with the same key.
     */
    public function isReplayable(): bool
    {
        return $this === self::Completed;
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
