<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Reconciliation;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What the bank says, what the books say, and the distance between them.
 *
 * A reconciliation is read rather than remembered: the statement's own figures,
 * the ledger's balance at the statement's closing date, and the difference a
 * person has to explain. Both halves are computed here every time somebody
 * opens the screen, which is what keeps the number a reader sees and the number
 * the completion action refuses over the same number.
 *
 * The statement's opening balance is not a figure anybody types. It is derived
 * from the first line's printed balance by walking that line's movement
 * backwards, and the last line's printed balance is the closing figure the bank
 * itself wrote down. Adding the movements to the derived opening and arriving
 * at the printed closing is therefore a check the file performs on itself: a
 * file that fails it is missing rows or printing them twice.
 *
 * The ledger side is read through LedgerQuery rather than queried here, so the
 * account's balance is the very figure the general ledger shows for it — posted
 * and reversed journals only, and a draft's lines left out.
 *
 * The bank and the company write the same movement on opposite sides: money
 * arriving in the account is the bank's credit, because from its side the
 * customer's balance is something it owes, and the company's own debit to the
 * asset. The statement figures here therefore follow the bank's columns, and
 * the ledger figure is the company's debit-minus-credit, which comes to the same
 * number in the same direction. That agreement is what makes the difference
 * mean anything.
 */
final class ReconciliationSummary
{
    public function __construct(private readonly LedgerQuery $ledger) {}

    /**
     * One account's statement lines, oldest first, in the order the statement
     * reads down the page.
     *
     * @return Collection<int, BankStatementLine>
     */
    public function lines(int $companyId, BankAccount $account): Collection
    {
        return BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('bank_account_id', (int) $account->getKey())
            ->orderBy('value_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * The matches recorded against a set of statement lines.
     *
     * @param  Collection<int, BankStatementLine>  $lines
     * @return Collection<int, Reconciliation>
     */
    public function matches(int $companyId, Collection $lines): Collection
    {
        $lineIds = $lines->pluck('id')->all();

        if ($lineIds === []) {
            return collect();
        }

        return Reconciliation::query()
            ->where('company_id', $companyId)
            ->whereIn('bank_statement_line_id', $lineIds)
            ->get();
    }

    /**
     * The statement, the ledger, the difference, and what is still undecided.
     *
     * @return array<string, mixed>
     */
    public function for(int $companyId, BankAccount $account): array
    {
        $lines = $this->lines($companyId, $account);
        $matches = $this->matches($companyId, $lines);

        $first = $lines->first();
        $last = $lines->last();

        // The file can only check itself if every line prints the balance the
        // account stood at after it. One line without it and the arithmetic
        // below would be answering a question the file never asked.
        $hasBalance = $lines->isNotEmpty()
            && $lines->every(static fn (BankStatementLine $line): bool => $line->balance_after !== null);

        $movement = Money::sum(
            $lines->map(fn (BankStatementLine $line): Money => $this->movement($line))->all()
        );

        $opening = $hasBalance
            ? Money::of((string) $first->balance_after)->minus($this->movement($first))
            : null;

        $closing = $hasBalance ? Money::of((string) $last->balance_after) : null;
        $expected = $opening?->plus($movement);
        $consistent = $expected !== null && $closing !== null && $expected->equals($closing);

        // The ledger is asked what it stood at on the date the statement closes
        // at, because that is the only date on which the two figures describe
        // the same moment. Movements entered after it belong to the next
        // statement.
        $asOf = $last?->value_date?->toDateString();
        $ledgerBalance = $this->ledger->accountBalance($companyId, (int) $account->account_id, $asOf);

        // The movements nobody matched and somebody deliberately ignored. They
        // stay in the statement's arithmetic — the bank really did print them —
        // but they are not in the books, so they are exactly the part of the
        // difference a completed reconciliation is allowed to leave standing.
        $ignoredMovement = Money::sum(
            $lines->where('status', BankStatementLineStatus::Ignored)
                ->map(fn (BankStatementLine $line): Money => $this->movement($line))
                ->all()
        );

        $completedAt = $matches->whereNotNull('reconciled_at')->max('reconciled_at');

        return [
            'bank_account_id' => (int) $account->getKey(),
            'ledger_account_id' => (int) $account->account_id,
            'currency_code' => $account->currency_code,
            'statement' => [
                'lines' => $lines->count(),
                'from' => $first?->value_date?->toDateString(),
                'to' => $asOf,
                'has_running_balance' => $hasBalance,
                'opening_balance' => $opening?->toDecimal(),
                'movement' => $movement->toDecimal(),
                'expected_closing_balance' => $expected?->toDecimal(),
                'closing_balance' => $closing?->toDecimal(),
                'internally_consistent' => $consistent,
                'ignored_movement' => $ignoredMovement->toDecimal(),
            ],
            'ledger' => [
                'balance' => $ledgerBalance->toDecimal(),
                'as_of' => $asOf,
            ],
            // Positive means the bank says the account holds more than the
            // books do: a receipt the company has not recorded, or a payment it
            // has recorded that the bank has not yet taken.
            'difference' => ($closing ?? Money::zero())->minus($ledgerBalance)->toDecimal(),
            'counts' => [
                'statement_lines' => $lines->count(),
                'outstanding' => $this->outstandingCount($lines, $matches),
                'matched' => $lines->where('status', BankStatementLineStatus::Matched)->count(),
                'ignored' => $lines->where('status', BankStatementLineStatus::Ignored)->count(),
                'tentative_matches' => $matches->whereNull('reconciled_at')->count(),
                'completed_matches' => $matches->whereNotNull('reconciled_at')->count(),
            ],
            'completed_at' => $completedAt === null ? null : (string) $completedAt,
        ];
    }

    /**
     * The lines still waiting for a decision: not ignored, and not covered by
     * matches that add up to their whole movement.
     *
     * A line half matched is still outstanding. Its movement is the bank's, the
     * ledger already holds the whole of the entry it was matched against, and
     * nothing in the difference between the two balances would reveal that half
     * of the line was never explained. Counting it here is what stops a
     * reconciliation being signed off on a partial story.
     *
     * @param  Collection<int, BankStatementLine>  $lines
     * @param  Collection<int, Reconciliation>  $matches
     */
    private function outstandingCount(Collection $lines, Collection $matches): int
    {
        $covered = [];

        foreach ($matches as $match) {
            $lineId = (int) $match->bank_statement_line_id;

            $covered[$lineId] = ($covered[$lineId] ?? Money::zero())
                ->plus(Money::of((string) $match->matched_amount));
        }

        return $lines
            ->filter(function (BankStatementLine $line) use ($covered): bool {
                if ($line->status === BankStatementLineStatus::Ignored) {
                    return false;
                }

                $matched = $covered[(int) $line->getKey()] ?? Money::zero();

                return ! $matched->equals($this->movement($line)->absolute());
            })
            ->count();
    }

    /**
     * One line's movement in the bank's direction: money arriving in the
     * account is positive, money leaving it is negative.
     */
    private function movement(BankStatementLine $line): Money
    {
        return Money::of((string) $line->credit)->minus(Money::of((string) $line->debit));
    }
}
