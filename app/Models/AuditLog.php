<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One link in the append-only audit chain.
 *
 * previous_hash and hash are NOT mass assignable, and there is no update path.
 * The BEFORE INSERT trigger locks audit_chain_head, copies the chain head into
 * previous_hash and computes hash with SHA2 over the canonical payload, then
 * the AFTER INSERT trigger advances the head. Two further triggers reject any
 * UPDATE or DELETE outright.
 *
 * The payload format is mirrored byte for byte by App\Support\AuditChain,
 * which is what php artisan audit:verify uses to detect tampering.
 */
#[Fillable([
    'ulid',
    'company_id',
    'user_id',
    'action',
    'auditable_type',
    'auditable_id',
    'ip_address',
    'user_agent',
    'before',
    'after',
    'meta',
])]
class AuditLog extends Model
{
    use HasPublicId;

    /**
     * The table has created_at only: a row is written once and never touched
     * again.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
