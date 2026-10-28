<?php

namespace App\Domain\Banking\DTOs;

/**
 * Everything TransferBetweenAccounts needs to move money between two of the
 * company's own cash or bank accounts.
 *
 * Both sides are named as `bank_accounts` rows rather than as ledger accounts,
 * for the same reason a receipt names one: the ledger account is derived from
 * the row, so the caller can only pick between accounts the company has opened
 * and every account carries a status the action is able to refuse.
 *
 * There is no live bank connection behind any of this. The two accounts are
 * rows a person created, and the transfer is a journal written in the books.
 */
final class TransferData
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $fromBankAccountId,
        public readonly int $toBankAccountId,
        public readonly string $transferDate,
        public readonly string $amount,
        public readonly ?string $reference = null,
        public readonly ?int $userId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}
}
