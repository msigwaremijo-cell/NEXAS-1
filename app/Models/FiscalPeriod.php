<?php

namespace App\Models;

use App\Domain\Accounting\Enums\FiscalStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One accounting period inside a fiscal year. The integrity trigger rejects
 * any posting whose period is not open, so this model only has to expose the
 * state cleanly.
 */
#[Fillable([
    'ulid',
    'company_id',
    'fiscal_year_id',
    'period_no',
    'name',
    'starts_on',
    'ends_on',
    'status',
    'closed_at',
    'closed_by_user_id',
    'row_version',
])]
class FiscalPeriod extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_no' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalStatus::class,
            'closed_at' => 'datetime',
            'row_version' => 'integer',
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
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * @return HasMany<Journal, $this>
     */
    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /**
     * @return HasMany<LedgerBalance, $this>
     */
    public function ledgerBalances(): HasMany
    {
        return $this->hasMany(LedgerBalance::class);
    }

    public function acceptsPostings(): bool
    {
        return $this->status->acceptsPostings();
    }

    public function containsDate(string $date): bool
    {
        return $date >= $this->starts_on->toDateString()
            && $date <= $this->ends_on->toDateString();
    }
}
