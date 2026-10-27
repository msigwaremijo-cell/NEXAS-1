<?php

namespace App\Models;

use App\Support\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Replay protection for mutating APIs.
 *
 * request_hash lets the server tell a genuine retry (same key, same payload)
 * from a client bug (same key, different payload).
 */
#[Fillable([
    'company_id',
    'scope',
    'key',
    'request_hash',
    'status',
    'response_type',
    'response_id',
    'response_status',
    'response_body',
    'completed_at',
])]
class IdempotencyKey extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_status' => 'integer',
            'response_body' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
