<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one receipt of stock cost, and how much of it is left.
 *
 * A layer is the unit of FIFO costing. It is created when stock arrives, it
 * carries the price that receipt was bought at, and it is drawn down oldest
 * first. That is why a layer belongs to a warehouse as much as to a product:
 * the stock that arrived in Dar es Salaam is not the stock that arrived in
 * Mwanza until somebody moves it, and it was bought at its own price.
 *
 * `quantity_received` and `unit_cost` are frozen by a trigger. Only
 * `quantity_remaining` moves, and only ever downwards, so an issue's cost
 * cannot be restated by rewriting a price after the fact.
 *
 * There is no soft delete: a layer that has been drawn down to nothing keeps
 * its row, because the consumption that emptied it points at it.
 */
#[Fillable([
    'company_id',
    'product_id',
    'warehouse_id',
    'receipt_movement_id',
    'received_on',
    'quantity_received',
    'quantity_remaining',
    'unit_cost',
    'row_version',
])]
class StockLayer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'quantity_received' => 'decimal:6',
            'quantity_remaining' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'row_version' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiptMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'receipt_movement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLayerConsumption::class, 'layer_id');
    }
}
