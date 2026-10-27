<?php

namespace App\Domain\Accounting\Exceptions;

use App\Support\Money;

/**
 * The proposed journal cannot be a valid double entry: too few lines, no
 * value at all, one side heavier than the other, or an account that is not
 * allowed to receive postings.
 */
final class InvalidJournalException extends AccountingException
{
    public static function tooFewLines(int $count): self
    {
        return new self(sprintf(
            'A journal needs at least two lines before it can be posted; %d given.',
            $count
        ));
    }

    public static function unbalanced(Money $debit, Money $credit): self
    {
        return new self(sprintf(
            'Journal is not balanced: total debits (%s) must equal total credits (%s).',
            $debit->toDecimal(),
            $credit->toDecimal()
        ));
    }

    public static function zeroValue(): self
    {
        return new self('A journal must have a value greater than zero before it can be posted.');
    }

    public static function unknownAccount(int $accountId): self
    {
        return new self(sprintf(
            'Account %d is not a live account of this company, so it cannot receive a posting.',
            $accountId
        ));
    }

    public static function notPostable(string $code): self
    {
        return new self(sprintf(
            'Account %s is a header account (is_postable = false) and must never receive a posting.',
            $code
        ));
    }
}
