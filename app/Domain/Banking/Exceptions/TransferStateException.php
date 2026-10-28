<?php

namespace App\Domain\Banking\Exceptions;

use App\Domain\Banking\Enums\BankAccountStatus;

/**
 * The transfer cannot be written as asked.
 *
 * A transfer touches two accounts instead of one, so each refusal names which
 * side it is about: a source account that is closed and a destination account
 * that is closed are the same rule but a different fix.
 */
final class TransferStateException extends BankingException
{
    public static function nonPositiveAmount(): self
    {
        return new self('A transfer must be for an amount greater than zero.');
    }

    public static function sameAccount(int $bankAccountId): self
    {
        return new self(sprintf(
            'Account %d cannot be transferred to itself, so no money would move.',
            $bankAccountId
        ));
    }

    public static function unknownAccount(string $side, int $bankAccountId): self
    {
        return new self(sprintf(
            'No cash or bank account with id %d belongs to this company, so there is nothing on the %s side of the transfer.',
            $bankAccountId,
            $side
        ));
    }

    public static function accountNotOpen(string $side, string $name, BankAccountStatus $status): self
    {
        return new self(sprintf(
            'Bank account %s is %s, so it cannot be the %s side of a transfer.',
            $name,
            $status->label(),
            $side
        ));
    }
}
