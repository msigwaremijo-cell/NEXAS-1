<?php

namespace App\Models;

use App\Domain\Assets\Enums\AssetStatus;
use App\Models\Concerns\HasPublicId;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of property, plant or equipment.
 *
 * The register holds two figures only the asset can know — what it cost and
 * what it is expected to be worth at the end of its life — and the date it was
 * placed in service. Where the monthly charge lands is the category's answer,
 * not the asset's.
 *
 * accumulated_depreciation is the asset's own figure rather than a share of
 * the ledger's balance, which is what lets a run know where it stands and what
 * lets the register tie out to the ledger. depreciated_through is the end of
 * the last period a run covered, so a period cannot be charged twice even if a
 * run is asked for the same window again.
 *
 * Status moves one way. A disposed asset is history: it is not depreciated,
 * not moved and not disposed of twice, and the only correction is to reverse
 * the disposal journal.
 */
#[Fillable([
    'ulid',
    'company_id',
    'asset_category_id',
    'code',
    'name',
    'description',
    'status',
    'acquisition_cost',
    'salvage_value',
    'useful_life_months',
    'in_service_date',
    'accumulated_depreciation',
    'depreciated_through',
    'disposed_on',
    'disposal_proceeds',
    'disposal_journal_id',
    'row_version',
])]
class Asset extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
            'acquisition_cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'accumulated_depreciation' => 'decimal:4',
            'disposal_proceeds' => 'decimal:4',
            'useful_life_months' => 'integer',
            'in_service_date' => 'date',
            'depreciated_through' => 'date',
            'disposed_on' => 'date',
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
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * The journal a disposal wrote, once one has.
     *
     * @return BelongsTo<Journal, $this>
     */
    public function disposalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'disposal_journal_id');
    }

    public function isDraft(): bool
    {
        return $this->status === AssetStatus::Draft;
    }

    public function isInService(): bool
    {
        return $this->status === AssetStatus::InService;
    }

    public function isDisposed(): bool
    {
        return $this->status === AssetStatus::Disposed;
    }

    /**
     * What is left to depreciate: cost less salvage less what has been charged.
     * Never negative, because the schedule floors a charge at the salvage
     * value.
     */
    public function depreciableRemaining(): Money
    {
        $remaining = Money::of($this->acquisition_cost)
            ->minus(Money::of($this->salvage_value))
            ->minus(Money::of($this->accumulated_depreciation));

        return $remaining->isNegative() ? Money::zero() : $remaining;
    }

    public function netBookValue(): Money
    {
        return Money::of($this->acquisition_cost)->minus(Money::of($this->accumulated_depreciation));
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->where('status', AssetStatus::InService->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('code', 'like', $like));
    }
}
