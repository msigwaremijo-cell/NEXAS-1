<?php

namespace App\Domain\Accounting\DTOs;

use App\Support\Money;

/**
 * The priced result of a set of document lines.
 *
 * `lines` is a list of arrays rather than models so the DTO can be produced
 * before anything is written: the caller decides whether the lines become
 * invoice_lines or bill_lines, and the same arithmetic serves both.
 *
 * @phpstan-type ComputedLine array{
 *     line_no: int,
 *     description: string,
 *     quantity: string,
 *     unit_price: string,
 *     discount_rate: string,
 *     discount_amount: string,
 *     tax_code_id: int|null,
 *     tax_rate: string,
 *     tax_amount: string,
 *     line_subtotal: string,
 *     line_total: string,
 *     product_id: int|null,
 *     account_id: int|null,
 * }
 */
final class ComputedDocument
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly Money $subtotal,
        public readonly Money $discountTotal,
        public readonly Money $taxTotal,
        public readonly Money $total,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
