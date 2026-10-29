<?php

namespace App\Domain\Banking\Exceptions;

use App\Support\Money;

/**
 * A reconciliation request that collides with the state of the books.
 *
 * These are 409s rather than 422s because the request is well formed: the
 * caller asked for something that is not available to ask for at this moment,
 * so sending the same request unchanged will produce the same answer every
 * time. Completing a statement whose arithmetic does not add up, releasing a
 * line a completed reconciliation has already closed, or signing off while a
 * line is still unmatched are all of that kind.
 *
 * Each is named before BankingException in bootstrap/app.php, since every one
 * of them is also a banking refusal.
 */
final class ReconciliationStateException extends BankingException
{
    public static function statementLineIsLocked(): self
    {
        return new self('This statement line was locked by a completed reconciliation. A signed-off reconciliation is evidence, so what it matched cannot be changed; reverse the sign-off with an adjusting entry instead.');
    }

    public static function nothingToComplete(?string $completedAt): self
    {
        return $completedAt === null
            ? new self('This account holds no statement lines, so there is nothing to reconcile yet. Import the statement file first.')
            : new self(sprintf(
                'Every statement line on this account was already reconciled when the statement was completed on %s. Import a later statement to reconcile the next period.',
                $completedAt
            ));
    }

    public static function statementHasNoRunningBalance(): self
    {
        return new self('This statement was imported without a running balance column, so the difference between the statement and the ledger cannot be established and the reconciliation cannot be completed. Import the statement with its balance column mapped.');
    }

    public static function statementBalanceInconsistent(
        Money $opening,
        Money $movement,
        Money $closing,
        Money $expected,
    ): self {
        return new self(sprintf(
            'The statement does not add up: it opens at %s, its movements total %s, which should close at %s, but the balance it prints for its last line is %s. A file whose own running balance does not follow its movements is missing or repeating rows, so reconciling against it would sign off a figure that is not the bank\'s.',
            $opening->toDecimal(),
            $movement->toDecimal(),
            $expected->toDecimal(),
            $closing->toDecimal()
        ));
    }

    /**
     * The one refusal the reader most often meets, so it names both figures:
     * how many statement lines are still undecided, and by exactly how much the
     * statement and the ledger disagree.
     */
    public static function incomplete(int $unmatchedLines, Money $difference): self
    {
        return new self(sprintf(
            'The statement cannot be completed yet: %d statement line%s still unmatched and the difference between the statement and the ledger is %s. Match or ignore the outstanding lines, and account for the difference, then complete again.',
            $unmatchedLines,
            $unmatchedLines === 1 ? ' is' : 's are',
            $difference->toDecimal()
        ));
    }
}
