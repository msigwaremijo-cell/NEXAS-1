<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One asset's share of one period's depreciation run.
 *
 * This is the run's own record of what it charged each asset, written inside
 * the same transaction as the journal it belongs to. It exists because a
 * journal line names an account rather than an asset: without this row a run
 * could not say what it charged a particular asset, and a reversal could not
 * say what to give back.
 *
 * The row is derived from the run and is deleted when the run is undone, so it
 * carries no soft delete of its own.
 */
#[Fillable([
    'ulid',
    'company_id',
    'asset_id',
    'fiscal_period_id',
    'journal_id',
    'amount',
])]
class AssetDepreciationLine extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
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
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
