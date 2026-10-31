<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\Reconciliation;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Proposes which ledger entry a statement line is the same movement as.
 *
 * Three signals, in the order a person reconciling by hand would use them:
 *
 *   amount       the two have to be the same movement, so the same magnitude on
 *                opposite sides. A bank writes money arriving in the account as
 *                a credit, because from its side the customer's balance is a
 *                liability it owes; the company's own books debit the asset.
 *                The matcher flips the side rather than the amount, so a
 *                statement line of money in only ever meets a debit.
 *   date window  the bank's value date and the company's posting date are
 *                rarely more than a few days apart, because a movement is
 *                entered when it happens and appears on the statement when the
 *                bank processes it. A candidate outside the window is not
 *                proposed at all rather than proposed and ranked low.
 *   reference    a matching reference is the strongest signal there is, so it
 *                outweighs any date difference inside the window.
 *
 * A proposal is only acted on when it is unambiguous. Two candidates on the
 * same score, a candidate already tied to another statement line, or a line
 * that looks like a repetition of another all mean a person has to look, so
 * they come back flagged instead of matched. Auto-matching is a convenience for
 * the obvious cases, never a decision the reader cannot see.
 *
 * Nothing here writes. The plan is applied by the caller, through the same
 * guarded action a manual match goes through, so an automatic match and a hand
 * match are the same object with the same rules behind it.
 */
final class StatementMatcher
{
    /**
     * How many days either side of a statement line's value date a ledger entry
     * may sit. Three days covers a weekend and the usual clearing gap; anything
     * wider starts pairing movements that merely happen to be near each other.
     */
    public const DEFAULT_DATE_WINDOW_DAYS = 3;

    public const MAX_DATE_WINDOW_DAYS = 30;

    /**
     * What a matching reference is worth on top of the date proximity score.
     */
    private const REFERENCE_BONUS = 10;

    public function __construct(private readonly LedgerQuery $ledger) {}

    /**
     * What can be matched without a human looking, and what cannot.
     *
     * @return array{
     *     applied: list<array{line: BankStatementLine, journal_line_id: int, amount: string, score: int, reasons: list<string>}>,
     *     flagged: list<array{line: BankStatementLine, reason: string}>,
     *     unmatched: list<array{line: BankStatementLine, reason: string}>,
     * }
     */
    public function plan(int $companyId, BankAccount $account, ?int $windowDays = null): array
    {
        $window = $this->window($windowDays);
        $bankAccountId = (int) $account->getKey();

        $open = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('bank_account_id', $bankAccountId)
            ->where('status', BankStatementLineStatus::Unmatched->value)
            ->whereDoesntHave('reconciliations')
            ->orderBy('value_date')
            ->orderBy('id')
            ->get()
            ->values();

        if ($open->isEmpty()) {
            return ['applied' => [], 'flagged' => [], 'unmatched' => []];
        }

        $candidates = $this->candidates($companyId, (int) $account->account_id, $open, $window);
        $repeated = array_flip($this->repeatedLineIds($companyId, $bankAccountId));
        $alreadyMatchedTo = $this->journalLinesAlreadyMatched($candidates->pluck('id')->all());

        $applied = [];
        $flagged = [];
        $unmatched = [];

        foreach ($open as $line) {
            $found = $this->rank($line, $candidates, $window);

            if ($found === []) {
                $unmatched[] = ['line' => $line, 'reason' => 'no_candidate'];

                continue;
            }

            $best = $found[0];

            if (count($found) > 1 && $found[1]['score'] === $best['score']) {
                $flagged[] = ['line' => $line, 'reason' => 'ambiguous'];

                continue;
            }

            if (isset($alreadyMatchedTo[$best['journal_line_id']])) {
                $flagged[] = ['line' => $line, 'reason' => 'possible_duplicate_journal_line'];

                continue;
            }

            if (isset($repeated[(int) $line->getKey()])) {
                $flagged[] = ['line' => $line, 'reason' => 'possible_duplicate_statement_line'];

                continue;
            }

            $applied[] = [
                'line' => $line,
                'journal_line_id' => $best['journal_line_id'],
                'amount' => $this->movement($line)->toDecimal(),
                'score' => $best['score'],
                'reasons' => $best['reasons'],
            ];
        }

        return ['applied' => $applied, 'flagged' => $flagged, 'unmatched' => $unmatched];
    }

    /**
     * The statement lines of one account that look like a repetition of another
     * line. The importer already refuses a file that carries the same movement
     * twice, but its fingerprint includes the date, so the same amount and
     * reference on two different dates gets through and is worth a look.
     *
     * @return list<int>
     */
    public function repeatedLineIds(int $companyId, int $bankAccountId): array
    {
        $groups = [];

        $lines = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('bank_account_id', $bankAccountId)
            ->get(['id', 'debit', 'credit', 'reference']);

        foreach ($lines as $line) {
            $groups[$this->repetitionKey($line)][] = (int) $line->getKey();
        }

        $repeated = [];

        foreach ($groups as $ids) {
            if (count($ids) > 1) {
                $repeated = array_merge($repeated, $ids);
            }
        }

        return $repeated;
    }

    /**
     * The ledger entries a statement line of this account could be.
     *
     * Bounded by the account and by the span of the lines being reconciled plus
     * the window, so the set stays small however long the ledger is. Only lines
     * that have reached the ledger are read: a draft's lines are physically in
     * journal_lines from the moment the draft is written, and a statement
     * cannot be reconciled against something the books have not posted.
     *
     * @param  Collection<int, BankStatementLine>  $open
     * @return Collection<int, JournalLine>
     */
    private function candidates(int $companyId, int $ledgerAccountId, $open, int $window): Collection
    {
        $dates = $open->map(fn (BankStatementLine $line): string => $line->value_date->toDateString())->sort()->values();

        $from = (new DateTimeImmutable((string) $dates->first()))->modify("-{$window} days")->format('Y-m-d');
        $to = (new DateTimeImmutable((string) $dates->last()))->modify("+{$window} days")->format('Y-m-d');

        return $this->ledger
            ->ledgerLineQuery($companyId, $ledgerAccountId, $from, $to)
            ->with('journal:id,journal_no,reference,status')
            ->get();
    }

    /**
     * Every candidate for one statement line, best first.
     *
     * @param  Collection<int, JournalLine>  $candidates
     * @return list<array{journal_line_id: int, score: int, reasons: list<string>}>
     */
    private function rank(BankStatementLine $line, $candidates, int $window): array
    {
        $amount = $this->movement($line);
        $moneyIn = $this->isMoneyIn($line);

        $found = [];

        foreach ($candidates as $journalLine) {
            if (! $journalLine->isDebit() === $moneyIn) {
                continue;
            }

            if (! $journalLine->amount()->equals($amount)) {
                continue;
            }

            $days = $this->daysApart($line->value_date, $journalLine->posting_date);

            if ($days > $window) {
                continue;
            }

            $reasons = [$days === 0 ? 'same_date' : 'date_window'];
            $score = $window - $days + 1;

            if ($this->referencesAgree($line->reference, $journalLine->journal?->reference)) {
                $score += self::REFERENCE_BONUS;
                $reasons[] = 'reference';
            }

            $found[] = [
                'journal_line_id' => (int) $journalLine->getKey(),
                'score' => $score,
                'reasons' => $reasons,
            ];
        }

        usort($found, fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $found;
    }

    /**
     * @param  array<int, int|string>  $journalLineIds
     * @return array<int, int> journal line id => the statement line it is already tied to
     */
    private function journalLinesAlreadyMatched(array $journalLineIds): array
    {
        if ($journalLineIds === []) {
            return [];
        }

        $used = [];

        foreach (Reconciliation::query()->whereIn('journal_line_id', $journalLineIds)->get() as $match) {
            $used[(int) $match->journal_line_id] = (int) $match->bank_statement_line_id;
        }

        return $used;
    }

    /**
     * The movement's magnitude, whichever side of the bank's columns it sits on.
     */
    private function movement(BankStatementLine $line): Money
    {
        return Money::of((string) $line->credit)
            ->minus(Money::of((string) $line->debit))
            ->absolute();
    }

    /**
     * Whether the bank printed this as money arriving in the account. Import
     * guarantees exactly one side is filled, so this is the line's direction.
     */
    private function isMoneyIn(BankStatementLine $line): bool
    {
        return Money::of((string) $line->credit)->isPositive();
    }

    /**
     * A key that is the same for two lines that look like one movement written
     * down twice: the same amount, the same reference.
     */
    private function repetitionKey(BankStatementLine $line): string
    {
        return $this->movement($line)->toDecimal().'|'.mb_strtolower(trim((string) $line->reference));
    }

    private function referencesAgree(?string $left, ?string $right): bool
    {
        $left = mb_strtolower(trim((string) $left));
        $right = mb_strtolower(trim((string) $right));

        return $left !== '' && $left === $right;
    }

    /**
     * Whole days between two dates, counted on the calendar rather than in
     * hours, so a value date and a posting date on the same day are never a day
     * apart because one was stored at midnight and the other at noon.
     */
    private function daysApart(DateTimeInterface $left, DateTimeInterface $right): int
    {
        $from = new DateTimeImmutable($left->format('Y-m-d'));
        $to = new DateTimeImmutable($right->format('Y-m-d'));

        return (int) $from->diff($to)->days;
    }

    private function window(?int $windowDays): int
    {
        return min(max($windowDays ?? self::DEFAULT_DATE_WINDOW_DAYS, 0), self::MAX_DATE_WINDOW_DAYS);
    }
}
