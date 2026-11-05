<?php

namespace App\Models;

use App\Domain\Assets\Enums\AssetCategoryStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class of fixed asset, and the four accounts its assets post to.
 *
 * Company-scoped master data with a status rather than a delete, because
 * assets already written point at it. The four accounts are what makes an
 * asset's depreciation land in the right place without the asset having to say
 * so: a vehicle carries its cost and its life, and this says which accounts the
 * monthly charge is written against.
 *
 * There is deliberately no accumulated-depreciation figure here. What a
 * category has cost to date is the sum of its assets, and reading it from the
 * register rather than keeping a second copy is what lets the register tie out
 * to the ledger against a single figure.
 */
#[Fillable([
    'ulid',
    'company_id',
    'code',
    'name',
    'description',
    'asset_account_id',
    'accumulated_depreciation_account_id',
    'depreciation_expense_account_id',
    'disposal_account_id',
    'default_useful_life_months',
    'status',
    'row_version',
])]
class AssetCategory extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssetCategoryStatus::class,
            'default_useful_life_months' => 'integer',
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
     * The account the asset's cost is carried in.
     *
     * @return BelongsTo<Account, $this>
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /**
     * The contra-asset the credit side of a depreciation charge lands on: an
     * asset account with a credit normal balance.
     *
     * @return BelongsTo<Account, $this>
     */
    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    /**
     * Where a gain or loss on disposal is booked.
     *
     * @return BelongsTo<Account, $this>
     */
    public function disposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_account_id');
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AssetCategoryStatus::Active->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('code', 'like', $like));
    }
}
