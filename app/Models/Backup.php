<?php

namespace App\Models;

use App\Domain\Backups\Enums\BackupStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One nightly backup: an encrypted file holding the whole database and the
 * documents filed against it, and the record of the run that wrote it.
 *
 * This is the only model in the application with no company behind it, because
 * a backup is not any one company's — it is every company's books in one file.
 *
 * The lifecycle is deliberately narrow. A run writes the row as running, and
 * moves it once, to completed or to failed. A failed run is not retried in
 * place: the night is over, and the next night is a new row, because a backup
 * that has already been attempted is a fact about that night rather than a row
 * to edit.
 */
#[Fillable([
    'ulid',
    'disk',
    'status',
    'path',
    'file_name',
    'size_bytes',
    'checksum_sha256',
    'database_name',
    'table_count',
    'row_count',
    'file_count',
    'error',
    'started_at',
    'completed_at',
    'pruned_at',
])]
class Backup extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BackupStatus::class,
            'size_bytes' => 'integer',
            'table_count' => 'integer',
            'row_count' => 'integer',
            'file_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    /**
     * The file is written, its checksum taken, and it may now be restored.
     *
     * The four figures are what the run saw as it took the copy: how many
     * tables and rows went in, and how many filed documents travelled with
     * them. They are written here rather than derived later, because a restore
     * drill needs something to compare the restored books against that does
     * not require unwrapping the file first.
     */
    public function markCompleted(
        string $path,
        string $fileName,
        int $sizeBytes,
        string $checksum,
        int $tableCount,
        int $rowCount,
        int $fileCount,
    ): void {
        $this->forceFill([
            'status' => BackupStatus::Completed,
            'path' => $path,
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'checksum_sha256' => $checksum,
            'table_count' => $tableCount,
            'row_count' => $rowCount,
            'file_count' => $fileCount,
            'error' => null,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => BackupStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * The retention policy has taken the file away. The row stays, because
     * that a copy was taken on this night is worth knowing long after the copy
     * itself has gone.
     */
    public function markPruned(): void
    {
        $this->forceFill([
            'path' => null,
            'file_name' => null,
            'pruned_at' => now(),
        ])->save();
    }

    /**
     * A finished file still on the disk.
     */
    public function isRestorable(): bool
    {
        return $this->status->isRestorable() && $this->path !== null;
    }

    /**
     * Finished backups whose file is still on the disk, newest first.
     */
    public function scopeRestorable(Builder $query): Builder
    {
        return $query
            ->where('status', BackupStatus::Completed->value)
            ->whereNotNull('path');
    }
}
