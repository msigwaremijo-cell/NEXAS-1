<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A committed CSV import: which file, into which set of books, and how much it
 * brought in.
 *
 * This is the record the idempotency rule reads. A run is written only when a
 * file is committed, so its presence means "these bytes have already been
 * taken into this company's books for this dataset" — which is why the second
 * upload of the same file is refused before a single row is prepared.
 *
 * The row is kept rather than deleted when the imported data is later amended:
 * that a file was imported on a given day, by whom, and how many rows it
 * carried is part of the history of the books, exactly as the audit entry
 * beside it is.
 */
#[Fillable([
    'ulid',
    'company_id',
    'dataset',
    'file_hash',
    'file_name',
    'rows_imported',
    'summary',
    'imported_by_user_id',
    'committed_at',
])]
class ImportRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'rows_imported' => 'integer',
            'committed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
