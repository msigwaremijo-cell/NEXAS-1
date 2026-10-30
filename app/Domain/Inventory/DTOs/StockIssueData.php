<?php

namespace App\Domain\Inventory\DTOs;

/**
 * Stock leaving a warehouse.
 *
 * There is no counter account here on purpose. What an issue costs comes out
 * of the layers, and where that cost goes is a property of the item rather
 * than of the movement: the product names the cost of sales account it is
 * issued against, so the same sku always reaches the same expense line without
 * anybody having to remember which one to pick.
 *
 * `sourceType`/`sourceId` name the thing the issue came from, where it came
 * from a document rather than a person. An invoice that issues its goods sets
 * them to the invoice line, so a later credit note can find exactly the
 * movement it has to answer instead of guessing by product and date. They are
 * null on a movement raised by hand, which is what leaves a manual issue
 * exactly as it was.
 */
final class StockIssueData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $productId,
        public readonly int $warehouseId,
        public readonly string $movementDate,
        public readonly string $quantity,
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
        public readonly ?int $userId = null,
        public readonly ?string $sourceType = null,
        public readonly ?int $sourceId = null,
    ) {}
}
