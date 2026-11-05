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
 *
 * `status` and `reverses_movement_id` arrive by raw ALTER in a later migration,
 * so they are declared here rather than read back from the schema.
 *
 * @property StockMovementStatus $status
 * @property int|null $reverses_movement_id
 */
#[Fillable([
    'ulid',
    'company_id',
    'product_id',
    'warehouse_id',
    'supplier_id',
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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Who a receipt's goods came from, where they came from a supplier.
     *
     * Null on everything that is not a purchase: an issue, a count, a transfer
     * and a non-purchase receipt such as opening stock belong to nobody, and a
     * bill may only clear the receipts of its own supplier.
     *
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * What has been taken off this receipt by the bills that settled it.
     *
     * Only meaningful on a receipt. A later bill may draw on what this leaves,
     * which is what makes a partial receipt and a partial bill add up rather
     * than overlap.
     */
    public function clearedQuantity(): string
    {
        return (string) GrniAllocation::query()
            ->where('receipt_movement_id', $this->getKey())
            ->sum('quantity');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * The movement this one undoes, when this is a reversal.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }

    /**
     * Which layers funded this movement, when it took stock out.
     *
     * @return HasMany<StockLayerConsumption, $this>
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
