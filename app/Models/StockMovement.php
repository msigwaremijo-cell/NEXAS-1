<?php

namespace App\Models;

use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One thing that happened to the stock.
 *
 * Quantity is signed, so the quantity on hand is the sum of this table and
 * nothing else. There is no running-balance column anywhere: a balance that is
 * stored can disagree with the movements that produced it, and the only way to
 * find out is to add them up and compare.
 *
 * A movement is never edited once posted. It is reversed, which writes a
 * second movement of the opposite type carrying `reverses_movement_id`, and
 * the unique index on that column is what makes "at most once" true even if
 * two callers ask at the same time.
 *
 * `journal_id` is the ledger entry the movement made, where there was one to
 * make. A receipt, an issue and an adjustment each change what the inventory
 * is worth and so each posts; a transfer between two of the company's own
 * warehouses changes the total in the inventory account by nothing, so it
 * posts nothing and leaves this column null.
 */
#[Fillable([
    'ulid',
    'company_id',
    'product_id',
    'warehouse_id',
    'movement_type',
    'movement_date',
    'status',
    'quantity',
    'unit_cost',
    'total_cost',
    'source_type',
    'source_id',
    'journal_id',
    'reverses_movement_id',
    'created_by_user_id',
])]
class StockMovement extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'status' => StockMovementStatus::class,
            'movement_date' => 'date',
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
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

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * The movement this one undoes, when this is a reversal.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }

    /**
     * Which layers funded this movement, when it took stock out.
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLayerConsumption::class, 'movement_id');
    }

    public function isPosted(): bool
    {
        return $this->status->isPosted();
    }

    public function isReversal(): bool
    {
        return $this->reverses_movement_id !== null;
    }
}
