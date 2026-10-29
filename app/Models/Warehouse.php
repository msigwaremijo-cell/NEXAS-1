<?php

namespace App\Models;

use App\Domain\Inventory\Enums\StockItemStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place stock is kept.
 *
 * Stock on hand is always answered per warehouse as well as per product,
 * because the same item in two places is not interchangeable until somebody
 * moves it: a warehouse is where a quantity physically is, and a FIFO layer
 * belongs to the warehouse that received it rather than to the product in
 * general. A transfer exists precisely to carry a layer from one to the other.
 */
#[Fillable([
    'ulid',
    'company_id',
    'branch_id',
    'code',
    'name',
    'address',
    'is_default',
    'status',
    'row_version',
])]
class Warehouse extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockItemStatus::class,
            'is_default' => 'boolean',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function layers(): HasMany
    {
        return $this->hasMany(StockLayer::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StockItemStatus::Active->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('code', 'like', $like));
    }
}
