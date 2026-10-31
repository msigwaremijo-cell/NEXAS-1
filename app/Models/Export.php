<?php

namespace App\Models;

use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Enums\ExportStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One rendered report file, and the state of rendering it.
 *
 * The lifecycle is deliberately narrow: a request writes the row as queued, a
 * worker moves it to running and then to done or failed, and the cleanup
 * removes the file but leaves the row so the record of what was asked for —
 * and of what went wrong if it failed — survives the file.
 *
 * `parameters` is the report's window as the caller gave it, kept verbatim so
 * the job can rebuild exactly what was asked for without the caller having to
 * send it twice.
 */
#[Fillable([
    'ulid',
    'company_id',
    'user_id',
    'type',
    'format',
    'status',
    'parameters',
    'disk',
    'path',
    'file_name',
    'size_bytes',
    'error',
    'started_at',
    'completed_at',
    'expires_at',
])]
class Export extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'format' => ExportFormat::class,
            'parameters' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Who asked for it.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * A finished file whose retention window has passed. A row that has never
     * been rendered has no expiry and is not expired; it is simply not done.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * A download can only be served from a file that still exists.
     */
    public function isDownloadable(): bool
    {
        return $this->status->isDownloadable()
            && $this->path !== null
            && ! $this->hasExpired();
    }

    /**
     * Where the file lives on its disk: one folder per company, named by the
     * export's public identifier, so nothing another tenant renders can ever
     * share a path with this one.
     */
    public function path(): string
    {
        return $this->company_id.'/'.$this->ulid.'.'.$this->format->extension();
    }

    /**
     * The name the file is handed back under. Readable rather than opaque,
     * because it is what lands in a downloads folder, with the identifier kept
     * on it so two exports of the same report do not overwrite each other.
     */
    public function fileName(): string
    {
        $stamp = $this->parameters['to']
            ?? $this->parameters['as_of']
            ?? $this->created_at?->toDateString()
            ?? now()->toDateString();

        return Str::slug($this->type).'-'.$stamp.'-'.$this->ulid.'.'.$this->format->extension();
    }

    public function markRunning(): void
    {
        $this->forceFill([
            'status' => ExportStatus::Running,
            'started_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * The file is written. The expiry is stamped from completion rather than
     * from the request, so a job queued behind a backlog is not born already
     * half-expired.
     */
    public function markDone(string $path, string $fileName, int $sizeBytes): void
    {
        $this->forceFill([
            'status' => ExportStatus::Done,
            'path' => $path,
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'error' => null,
            'completed_at' => now(),
            'expires_at' => now()->addHours((int) config('nexas.exports.retention_hours')),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => ExportStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
            'expires_at' => null,
        ])->save();
    }

    /**
     * Finished files whose retention window has passed, oldest first.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query
            ->where('status', ExportStatus::Done->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
