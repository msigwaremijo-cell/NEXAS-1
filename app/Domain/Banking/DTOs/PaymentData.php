<?php

namespace App\Domain\Banking\DTOs;

use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Banking\Enums\PaymentDirection;
use App\Domain\Banking\Enums\PaymentMethod;

/**
 * Everything RecordPayment needs to write one receipt or cash payment.
 *
 * `bankAccountId` is the place the money actually moved through, named as the
 * company's own cash or bank account rather than as a ledger account. The
 * ledger account is derived from it, which is the point: a caller can only
 * choose between accounts the company has opened, and each of those carries a
 * status the action can refuse. Passing a raw ledger account would let a
 * receipt be posted into an account nobody is watching, or into one that has
 * been closed.
 *
 * Each entry in `allocations` is
 * ['document_type' => 'invoice'|'bill', 'document_id' => int, 'amount' => string].
 */
final class PaymentData
{
    /**
     * @param  array<int, array{document_type: string, document_id: int, amount: string}>  $allocations
     */
    public function __construct(
        public readonly int $companyId,
        public readonly PaymentDirection $direction,
        public readonly PartyType $partyType,
        public readonly int $partyId,
        public readonly int $bankAccountId,
        public readonly string $paymentDate,
        public readonly string $amount,
        public readonly PaymentMethod $method = PaymentMethod::Bank,
        public readonly array $allocations = [],
        public readonly ?string $currencyCode = null,
        public readonly string $exchangeRate = '1',
        public readonly ?string $reference = null,
        public readonly ?int $userId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
