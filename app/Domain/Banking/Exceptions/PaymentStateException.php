<?php

namespace App\Domain\Banking\Exceptions;

use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Banking\Enums\BankAccountStatus;
use App\Domain\Banking\Enums\PaymentDirection;
use App\Support\Money;

/**
 * The receipt or payment cannot be written as asked.
 *
 * The allocation rules live here rather than in the controller because they are
 * the same whichever door the request came through: a cashier at the counter,
 * an importer reading a bank file, or a console command fixing a mistake.
 */
final class PaymentStateException extends BankingException
{
    public static function nonPositiveAmount(): self
    {
        return new self('A payment must be for an amount greater than zero.');
    }

    public static function unknownBankAccount(int $bankAccountId): self
    {
        return new self(sprintf(
            'No cash or bank account with id %d belongs to this company, so the money has nowhere to land.',
            $bankAccountId
        ));
    }

    public static function bankAccountNotOpen(string $name, BankAccountStatus $status): self
    {
        return new self(sprintf(
            'Bank account %s is %s, so it no longer takes new receipts or payments.',
            $name,
            $status->label()
        ));
    }

    public static function overAllocated(Money $amount, Money $allocated): self
    {
        return new self(sprintf(
            'The allocations total %s, which is more than the payment of %s.',
            $allocated->toDecimal(),
            $amount->toDecimal()
        ));
    }

    public static function wrongPartyForDirection(PaymentDirection $direction, PartyType $partyType): self
    {
        $expected = $direction->isInflow() ? PartyType::Customer : PartyType::Supplier;

        return new self(sprintf(
            'A %s must move against a %s, not a %s.',
            $direction->label(),
            $expected->label(),
            $partyType->label()
        ));
    }

    public static function unknownDocument(string $documentType, int $documentId): self
    {
        return new self(sprintf(
            'No %s with id %d belongs to this company, so nothing can be allocated to it.',
            $documentType,
            $documentId
        ));
    }

    public static function documentNotOpen(string $documentType, string $documentNo, string $status): self
    {
        return new self(sprintf(
            '%s %s is %s, so it no longer expects money.',
            ucfirst($documentType),
            $documentNo,
            $status
        ));
    }

    public static function exceedsOutstanding(string $documentNo, Money $outstanding, Money $requested): self
    {
        return new self(sprintf(
            '%s still owes %s; an allocation of %s would overpay it.',
            $documentNo,
            $outstanding->toDecimal(),
            $requested->toDecimal()
        ));
    }

    public static function documentPartyMismatch(string $documentNo, string $partyName): self
    {
        return new self(sprintf(
            '%s was issued to another party, so %s cannot settle it.',
            $documentNo,
            $partyName
        ));
    }
}
