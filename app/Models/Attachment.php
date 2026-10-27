<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A stored file. The row records a SHA-256 checksum so an upload can be
 * verified after the fact; it is also what a bank statement import uses to
 * refuse the same file twice.
 */
#[Fillable([
    'ulid',
    'company_id',
    'attachable_type',
    'attachable_id',
    'original_name',
    'disk',
    'path',
    'mime_type',
    'size_bytes',
    'checksum_sha256',
    'uploaded_by_user_id',
])]
class Attachment extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
