<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transactional outbox. The event row is written in the same transaction as
 * the state change it describes, so a queue outage can delay delivery but can
 * never lose the event.
 */
#[Fillable([
    'ulid',
    'company_id',
    'event_type',
    'aggregate_type',
    'aggregate_id',
    'payload',
    'status',
    'attempts',
    'available_at',
    'processed_at',
    'last_error',
])]
class OutboxEvent extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
