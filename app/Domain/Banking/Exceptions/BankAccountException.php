<?php

namespace App\Domain\Banking\Exceptions;

use App\Models\Account;

/**
 * A cash or bank account cannot be opened as asked.
 *
 * These are all things the caller can fix and resubmit, so they surface as a
 * 422 through the BankingException mapping rather than as a 409.
 */
final class BankAccountException extends BankingException
{
    public static function headerAccount(Account $account): self
    {
        return new self(sprintf(
            'Account %s (%s) is a header account, so no money can be held in it.',
            $account->code,
            $account->name
        ));
    }

    public static function openingDateMissing(): self
    {
        return new self(
            'An opening balance needs the date it is being brought in on, because that '
            .'is the date the opening journal is posted to.'
        );
    }

    public static function accountTypeNotCash(Account $account): self
    {
        return new self(sprintf(
            'Account %s (%s) is not flagged as a cash or bank account, so a bank account '
            .'cannot be linked to it.',
            $account->code,
            $account->name
        ));
    }
}
