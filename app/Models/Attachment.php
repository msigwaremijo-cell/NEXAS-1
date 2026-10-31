<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stored file, and the record it is filed against.
 *
 * The row records a SHA-256 checksum so an upload can be verified after the
 * fact; it is also what a bank statement import uses to refuse the same file
 * twice.
 *
 * `attachable_type` and `attachable_id` point at the record the document is
 * filed beside — a journal, an invoice, a bill, a payment, an asset. Filing a
 * document never changes that record: the document is kept beside it, which is
 * what lets one be added to a posted journal without touching the journal.
 *
 * A withdrawal is soft when the record it was filed against is posted, so the
 * account of the document survives its file. A draft record's documents are
 * removed outright.
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
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'deleted_at' => 'datetime',
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

    /**
     * The name the file is handed back under.
     *
     * The stored name is a ULID and means nothing to a human, so a download
     * carries the name the document had when it was filed. It is already
     * stripped of any directory the caller put in it, because it ends up in a
     * Content-Disposition header.
     */
    public function downloadName(): string
    {
        return (string) $this->original_name;
    }
}
