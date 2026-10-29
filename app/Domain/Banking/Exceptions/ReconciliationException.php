<?php

namespace App\Domain\Banking\Exceptions;

use App\Support\Money;

/**
 * A match the caller asked for that cannot be made as described.
 *
 * These are 422s: the journal line named does not belong to this account, the
 * allocation would over-cover one of the two sides, or no reason was given for
 * ignoring a line. All of them are things the caller can correct and send
 * again, which is the distinction that separates this class from
 * ReconciliationStateException.
 */
class ReconciliationException extends BankingException
{
    public static function noAllocations(): self
    {
        return new self('A match needs at least one allocation naming a journal line and the amount it covers.');
    }

    public static function amountNotPositive(int $journalLineId): self
    {
        return new self(sprintf(
            'The allocation against journal line %d must be an amount greater than zero. A match that covers nothing is not a match.',
            $journalLineId
        ));
    }

    public static function unknownBankAccount(int $bankAccountId): self
    {
        return new self(sprintf(
            'Bank account %d does not exist in these books.',
            $bankAccountId
        ));
    }

    public static function unknownStatementLine(int $statementLineId): self
    {
        return new self(sprintf(
            'Statement line %d is not one of this account\'s statement lines.',
            $statementLineId
        ));
    }

    public static function unknownReconciliation(int $reconciliationId): self
    {
        return new self(sprintf(
            'Match %d does not exist in these books.',
            $reconciliationId
        ));
    }

    public static function lineNotIgnored(): self
    {
        return new self('This statement line is not ignored, so there is nothing to release back to unmatched.');
    }

    public static function duplicateAllocation(int $journalLineId): self
    {
        return new self(sprintf(
            'Journal line %d is named twice in the same match. A ledger entry can only be tied to a statement line once, so the two allocations have to be added together into one amount.',
            $journalLineId
        ));
    }

    public static function unknownJournalLine(int $journalLineId): self
    {
        return new self(sprintf(
            'Journal line %d does not exist in these books.',
            $journalLineId
        ));
    }

    public static function journalLineOutsideTheAccount(int $journalLineId, string $accountCode): self
    {
        return new self(sprintf(
            'Journal line %d is not a movement on this account. A statement line for the account backed by ledger account %s can only be matched to a line on that same account; a movement on another account belongs to that account\'s own reconciliation.',
            $journalLineId,
            $accountCode
        ));
    }

    public static function journalLineNotInTheLedger(int $journalLineId): self
    {
        return new self(sprintf(
            'Journal line %d belongs to a journal that has not been posted, so it is not in the ledger and a statement cannot be reconciled against it. Post the journal first.',
            $journalLineId
        ));
    }

    public static function sideMismatch(int $journalLineId): self
    {
        return new self(sprintf(
            'Journal line %d sits on the wrong side of this movement. Money arriving in a bank account is a debit to that account in these books, and money leaving it is a credit, whichever way round the bank prints its own columns.',
            $journalLineId
        ));
    }

    public static function overMatchedStatementLine(Money $lineAmount, Money $alreadyMatched, Money $adding): self
    {
        return new self(sprintf(
            'This statement line is a movement of %s, but %s is already matched to it and this match would add %s. Together that is more than the movement; reduce the allocation or release the earlier match first.',
            $lineAmount->toDecimal(),
            $alreadyMatched->toDecimal(),
            $adding->toDecimal()
        ));
    }

    public static function overMatchedJournalLine(Money $lineAmount, Money $alreadyMatched): self
    {
        return new self(sprintf(
            'This journal line is a movement of %s and %s of it is already matched to another statement line. The same ledger entry cannot be spent twice.',
            $lineAmount->toDecimal(),
            $alreadyMatched->toDecimal()
        ));
    }

    public static function ignoredReasonRequired(): self
    {
        return new self('Ignoring a statement line needs a written reason. Ignoring is the deliberate exception that takes a movement out of the reconciliation, and an exception with no justification cannot be told apart from an oversight.');
    }

    public static function lineAlreadyIgnored(): self
    {
        return new self('This statement line is already ignored. Release it back to unmatched before matching it to a journal line.');
    }

    public static function lineHasMatches(): self
    {
        return new self('This statement line is still matched to one or more journal lines. Release those matches before ignoring it.');
    }
}
