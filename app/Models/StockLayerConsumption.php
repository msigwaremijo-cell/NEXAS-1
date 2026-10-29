<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One draw-down of one layer.
 *
 * Written at the moment stock leaves, and never changed afterwards. It is what
 * makes an issue reversible: the layers that funded it and the price each of
 * them charged are recorded here, so putting the stock back is a matter of
 * reading them rather than of recomputing a cost from a chart that has moved
 * on since.
 *
 * `quantity` is always positive — this is how much left the layer — and the
 * sign of the movement it belongs to says which way the stock went.
 */
#[Fillable([
    'company_id',
    'movement_id',
    'layer_id',
    'quantity',
    'consumed_on',
    'unit_cost',
    'total_cost',
])]
class StockLayerConsumption extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'consumed_on' => 'date',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class, 'layer_id');
    }
}
