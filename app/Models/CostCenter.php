<?php

namespace App\Models;

use App\Domain\Reporting\Enums\CostCenterStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A part of the company a plan may be split across.
 *
 * This is a dimension of the plan and not of the books. The ledger has no cost
 * centre column, so a journal line never names one and nothing here changes
 * what a posted entry looks like. What it does is let a budget say which part
 * of the business an amount belongs to, which the chart of accounts cannot draw
 * without inventing an account for every department.
 *
 * Modelled on a warehouse: company-scoped master data with a status rather than
 * a delete, because plan lines already written point at it.
 */
#[Fillable([
    'ulid',
    'company_id',
    'code',
    'name',
    'description',
    'status',
    'row_version',
])]
class CostCenter extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CostCenterStatus::class,
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CostCenterStatus::Active->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('code', 'like', $like));
    }
}
