<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\DTOs\MatchAllocationData;
use App\Domain\Banking\DTOs\MatchData;
use App\Domain\Banking\Enums\MatchType;
use App\Domain\Banking\Exceptions\ReconciliationException;
use App\Domain\Banking\Services\StatementMatcher;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Support\Money;

/**
 * Works the statement down the page, pairing the movements that can only be
 * one thing and leaving the rest for a person.
 *
 * The reading is done by StatementMatcher, which decides and writes nothing;
 * this action is the hand that carries those decisions out. Every proposal is
 * applied through MatchStatementLine, the same guarded door a hand match goes
 * through, so a match made here is the same row with the same checks behind it
 * and the match_type column is the only thing telling a reviewer which is
 * which. Nothing here reaches the ledger: the books already hold every movement
 * a match can find, and matching only records which entry the bank's line is.
 *
 * The matcher only proposes what is unambiguous, but it plans against a
 * snapshot read before any of it is written. Two statement lines of the same
 * amount and side can both be pointed at one ledger entry, and the first match
 * spends it. Rather than trust the plan, each proposal is put to the guard, and
 * one the guard refuses comes back flagged with the reason it gave — which is
 * the honest answer: the machine thought it was obvious, the rules say a person
 * has to look.
 *
 * So a run answers three ways, and the caller shows all three:
 *
 *   matched    applied, and now a tentative match on the line
 *   flagged    the matcher would not decide, or the guard would not allow it
 *   unmatched  nothing in the ledger looked like this movement at all
 */
final class AutoMatchStatementLines
{
    public function __construct(
        private readonly StatementMatcher $matcher,
        private readonly MatchStatementLine $match,
    ) {}

    /**
     * @return array{
     *     matched: list<array<string, mixed>>,
     *     flagged: list<array<string, mixed>>,
     *     unmatched: list<array<string, mixed>>,
     * }
     */
    public function __invoke(
        int $companyId,
        int $bankAccountId,
        ?int $windowDays = null,
        ?int $userId = null,
    ): array {
        $account = BankAccount::query()
            ->where('company_id', $companyId)
            ->whereKey($bankAccountId)
            ->first();

        if ($account === null) {
            throw ReconciliationException::unknownBankAccount($bankAccountId);
        }

        $plan = $this->matcher->plan($companyId, $account, $windowDays);

        $matched = [];
        $flagged = [];

        foreach ($plan['flagged'] as $proposal) {
            $flagged[] = $this->flagged($proposal['line'], $proposal['reason'], null);
        }

        foreach ($plan['applied'] as $proposal) {
            $line = $proposal['line'];

            try {
                ($this->match)(new MatchData(
                    companyId: $companyId,
                    bankAccountId: $bankAccountId,
                    statementLineId: (int) $line->getKey(),
                    allocations: [new MatchAllocationData($proposal['journal_line_id'], $proposal['amount'])],
                    matchType: MatchType::Auto,
                    userId: $userId,
                ));
            } catch (ReconciliationException $refused) {
                // The plan looked at the books as they stood before this run
                // started. An entry two lines both point at is spent by the
                // first, so the second is refused here and handed back for a
                // person to place.
                $flagged[] = $this->flagged($proposal['line'], 'refused', $refused->getMessage());

                continue;
            }

            $matched[] = [
                'statement_line_id' => (int) $line->getKey(),
                'statement_line_ulid' => $line->ulid,
                'value_date' => $line->value_date?->toDateString(),
                'amount' => $proposal['amount'],
                'journal_line_id' => $proposal['journal_line_id'],
                'score' => $proposal['score'],
                'reasons' => $proposal['reasons'],
            ];
        }

        return [
            'matched' => $matched,
            'flagged' => $flagged,
            'unmatched' => array_map(
                fn (array $proposal): array => $this->flagged($proposal['line'], $proposal['reason'], null),
                $plan['unmatched'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flagged(BankStatementLine $line, string $reason, ?string $detail): array
    {
        return [
            'statement_line_id' => (int) $line->getKey(),
            'statement_line_ulid' => $line->ulid,
            'value_date' => $line->value_date?->toDateString(),
            'amount' => $this->movement($line),
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /**
     * The movement's magnitude, whichever side of the bank's columns it sits
     * on, as a decimal string so it survives the trip to JSON unrounded.
     */
    private function movement(BankStatementLine $line): string
    {
        return Money::of((string) $line->credit)
            ->minus(Money::of((string) $line->debit))
            ->absolute()
            ->toDecimal();
    }
}
