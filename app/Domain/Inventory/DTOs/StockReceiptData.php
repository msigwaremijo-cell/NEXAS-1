<?php

namespace App\Domain\Inventory\DTOs;

/**
 * Stock arriving in a warehouse.
 *
 * The counter account is the caller's to name, because what a receipt was met
 * by depends on why the goods came: a purchase brings them against what the
 * supplier is owed, a return brings them back against cost of sales, and an
 * owner's contribution brings them against capital. Naming it here rather than
 * deriving it from the product is what keeps a receipt usable before Stage 8B
 * connects purchases to stock.
 *
 * `unitCost` is the price this delivery was bought at. It becomes the layer's
 * own cost, and it is what every later issue out of this layer will be priced
 * at — so it is the one figure in the module that cannot be corrected later
 * without reversing the movement.
 */
final class StockReceiptData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $movementDate,
        public readonly string $quantity,
        public readonly string $unitCost,
        public readonly int $counterAccountId,
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
        public readonly ?int $userId = null,
    ) {}
}
