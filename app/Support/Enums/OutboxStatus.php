<?php

namespace App\Support\Enums;

/**
 * Mirrors the `outbox_events.status` column comment
 * ('pending|processing|processed|failed').
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';

    /**
     * A pending or failed event is still owed to the consumer.
     */
    public function isOutstanding(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Processed => 'Processed',
            self::Failed => 'Failed',
        };
    }
}
