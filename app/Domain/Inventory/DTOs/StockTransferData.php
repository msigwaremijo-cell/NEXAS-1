<?php

namespace App\Domain\Inventory\DTOs;

/**
 * Stock moving between two of the company's own warehouses.
 *
 * A transfer writes two movements — one out of the source, one into the
 * destination — and not one. The quantity on hand is per warehouse, so a
 * single row could not say that the stock left one place and arrived at
 * another; and the two halves are what let the destination hold its own layer,
 * priced at what the stock cost when it left.
 *
 * Neither half posts a journal. The inventory account is one account across
 * the whole company, so stock moving between the company's own shelves changes
 * what it is worth by nothing at all. What it does change is which warehouse
 * the layers belong to, and that is exactly what the two movements record.
 */
final class StockTransferData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $productId,
        public readonly int $fromWarehouseId,
        public readonly int $toWarehouseId,
        public readonly string $movementDate,
        public readonly string $quantity,
        public readonly ?string $reference = null,
        public readonly ?string $memo = null,
        public readonly ?int $userId = null,
    ) {}
}
