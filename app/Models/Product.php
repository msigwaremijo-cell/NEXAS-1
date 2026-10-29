<?php

namespace App\Models;

use App\Domain\Inventory\Enums\ProductType;
use App\Domain\Inventory\Enums\StockItemStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something the company buys, sells or counts.
 *
 * The three account columns are what make a stock movement post itself. An
 * issue credits the inventory account the product names and debits the expense
 * account, so the cost of the goods that left lands on the product's own cost
 * of sales account rather than on one account somebody had to remember to
 * configure. A null inventory account means the product is not carried as
 * stock, and the actions refuse to move it.
 *
 * `unit_cost` is a reference and not a valuation: what the stock is actually
 * worth comes from its layers, never from this column. It survives as the
 * default the receipt screen offers, which is a convenience rather than a
 * figure anything posts from.
 */
#[Fillable([
    'ulid',
    'company_id',
    'sku',
    'name',
    'unit',
    'product_type',
    'inventory_account_id',
    'revenue_account_id',
    'expense_account_id',
    'tax_code_id',
    'unit_cost',
    'sale_price',
    'status',
    'row_version',
])]
class Product extends Model
{
    use HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'status' => StockItemStatus::class,
            'unit_cost' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The asset account this product's stock is carried in.
     */
    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_account_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    /**
     * Where the cost of this product's stock goes when it is issued — cost of
     * goods sold, or a more specific cost account where one exists.
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
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

    public function scopeGoods(Builder $query): Builder
    {
        return $query->where('product_type', ProductType::Goods->value);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(fn (Builder $inner): Builder => $inner
            ->where('name', 'like', $like)
            ->orWhere('sku', 'like', $like));
    }

    /**
     * Whether this product can be counted at all. A service has no quantity on
     * hand, so asking for one is a question with no answer rather than a zero.
     */
    public function holdsStock(): bool
    {
        return $this->product_type->holdsStock();
    }
}
