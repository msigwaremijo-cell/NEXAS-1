<?php

namespace App\Models;

use App\Domain\Accounting\Enums\FiscalStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A financial year. Postings are only accepted while both the year and the
 * matching period are open; the trigger enforces it.
 */
#[Fillable(['ulid', 'company_id', 'name', 'starts_on', 'ends_on', 'status', 'row_version'])]
class FiscalYear extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalStatus::class,
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class)->orderBy('period_no');
    }

    public function documentSequences(): HasMany
    {
        return $this->hasMany(DocumentSequence::class);
    }

    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }
}
