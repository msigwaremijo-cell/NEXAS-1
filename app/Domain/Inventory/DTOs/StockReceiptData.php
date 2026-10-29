<?php

namespace App\Domain\Inventory\DTOs;

/**
 * Stock arriving in a warehouse.
 *
 * There are two kinds of receipt, and which one this is depends on whether it
 * names a supplier.
 *
 * A **purchase receipt** names `supplierId`. Its goods were bought, so it
 * credits the company's goods received not invoiced account and opens the
 * layer the bill for it will later clear. `counterAccountId` is ignored in that
 * case: the account is not the caller's to choose, and letting it be named
 * would let a receipt credit something the bill could never clear.
 *
 * A **non-purchase receipt** — opening stock, a customer return, an owner's
 * contribution — names no supplier and must name `counterAccountId`, because
 * what such a delivery was met by is a matter of why it came rather than of
 * what it is. It is never cleared by a bill: nothing is owed for it.
 *
 * `unitCost` is the price this delivery was booked at. It becomes the layer's
 * own cost, and it is what every later issue out of this layer will be priced
 * at — so it is the one figure in the module that cannot be corrected later
 * without reversing the movement. On a purchase it is also the figure a bill
 * has to agree with, and the difference lands on the variance account.
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
        public readonly ?int $counterAccountId = null,
        public readonly ?int $supplierId = null,
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
        public readonly ?int $userId = null,
    ) {}
}
