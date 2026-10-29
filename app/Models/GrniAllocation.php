<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bill line's draw on one goods receipt.
 *
 * This is the record that makes "a bill for a stock item must not create a
 * second receipt" checkable rather than merely intended: the receipt moved the
 * stock and credited GRNI, and each of these rows says how much of that GRNI a
 * bill line has now cleared. What is left of a receipt — its quantity less
 * what these rows have taken — is what a later bill may still draw on, which is
 * what makes partial receipts and partial bills add up instead of overlapping.
 *
 * The value is frozen here rather than derived from the receipt, so a bill
 * always clears exactly what it cleared even if somebody reverses the receipt
 * (which is refused) or the product's costing method is questioned later.
 */
#[Fillable([
    'company_id',
    'bill_line_id',
    'receipt_movement_id',
    'quantity',
    'unit_cost',
    'value',
])]
class GrniAllocation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:4',
        ];
    }

    public function billLine(): BelongsTo
    {
        return $this->belongsTo(BillLine::class);
    }

    /**
     * The receipt whose goods this allocation settles.
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'receipt_movement_id');
    }
}
