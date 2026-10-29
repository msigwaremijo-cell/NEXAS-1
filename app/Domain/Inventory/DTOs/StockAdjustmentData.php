<?php

namespace App\Domain\Inventory\DTOs;

/**
 * A stock count disagreeing with the books.
 *
 * `quantity` is signed, and the sign is the whole of it: a positive figure is
 * stock the count found that the books did not have, a negative figure is
 * stock the books had that the count could not find. An adjustment is the only
 * movement that carries its own direction rather than having one implied by
 * its type.
 *
 * A shortfall is priced by the layers like any other stock leaving, so finding
 * less than the books say costs exactly what that stock had cost. Stock found
 * cannot be priced that way — there is no layer it came from — so
 * `unitCost` is required whenever the adjustment adds stock, and is what the
 * new layer is opened at.
 */
final class StockAdjustmentData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $movementDate,
        public readonly string $quantity,
        public readonly int $counterAccountId,
        public readonly ?string $unitCost = null,
        public readonly ?string $reason = null,
        public readonly ?int $userId = null,
    ) {}
}
