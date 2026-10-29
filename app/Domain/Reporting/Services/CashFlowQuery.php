<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the company's money came from and where it went.
 *
 * Two reports, built from one rule. `position()` says what is in each account
 * as at a date; `statement()` says how the total moved over a period. They
 * share the definition of which accounts hold the money, so the closing figure
 * of one and the total of the other are the same figure read twice, and a test
 * asserts they agree.
 *
 * The money accounts are the ones the chart flags `is_cash` or `is_bank`. That
 * flag, not a list in code, is what decides the pool: a company that adds a
 * third bank account to its chart gets it into both reports without a change
 * here.
 *
 * The statement is built from the other side of each entry rather than from the
 * cash side, and that is the whole of the method. Double entry says a journal's
 * debits equal its credits, so whatever the cash lines moved, the non-cash lines
 * moved the opposite way: a receipt from a customer debits the bank and credits
 * receivables, and it is the credit to receivables — an operating account, an
 * inflow — that classifies the movement. Reading it this way costs nothing and
 * buys two things. The classification is a sum of values MySQL already stores
 * in decimal columns, so it is exact: no proportion is apportioned across
 * categories and no rounding can accumulate. And the arithmetic cannot fail to
 * close, because the counter lines of a journal are the cash lines of that
 * journal with the sign flipped, by definition rather than by care.
 *
 * Internal transfers need no special case, which is worth spelling out because
 * it looks like the rule must be missing. Moving money between two of the
 * company's own accounts produces cash lines on both sides and no counter line
 * at all, so it contributes nothing to any activity. There is nothing to
 * exclude: it was never counted.
 *
 * Only journals in the ledger count, which is posted and reversed ones. That
 * rule lives in LedgerQuery and is reached through it rather than restated
 * here.
 */
final class CashFlowQuery
{
    public function __construct(private readonly LedgerQuery $ledger) {}

    /**
     * The cash flow statement for a period.
     *
     * `from` is inclusive, and opening cash is what the accounts stood at the
     * day before it, so a statement run for March does not count February's
     * closing movement twice. Omitting both dates reports the whole ledger,
     * opening at zero.
     *
     * @return array<string, mixed>
     */
    public function statement(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $accounts = $this->moneyAccounts($companyId);
        $accountIds = $this->ids($accounts);

        $opening = $from === null
            ? Money::zero()
            : $this->totalCash($companyId, $accountIds, $this->dayBefore($from));

        $closing = $this->totalCash($companyId, $accountIds, $to);

        $movements = $this->classify($companyId, $accountIds, $from, $to);

        $netActivities = Money::sum(array_map(
            static fn (array $line): Money => Money::of($line['net']),
            $movements['activities'],
        ));

        $net = $netActivities
            ->plus(Money::of($movements['internal_transfers']['net']))
            ->plus(Money::of($movements['unclassified']['net']));

        return [
            'from' => $from,
            'to' => $to,
            'activities' => $movements['activities'],
            'net_activities' => $netActivities->toDecimal(),
            'internal_transfers' => $movements['internal_transfers'],
            'unclassified' => $movements['unclassified'],
            'net_movement' => $net->toDecimal(),
            'opening_cash' => $opening->toDecimal(),
            'closing_cash' => $closing->toDecimal(),
            // Opening plus the movement it has just described, against a
            // closing figure read independently out of the ledger. The two are
            // computed from different queries on purpose: if this is ever false
            // the report is wrong, not merely unbalanced.
            'balanced' => $opening->plus($net)->equals($closing),
            'complete' => Money::of($movements['unclassified']['net'])->isZero(),
            // Stated rather than implied, because the categories are read from
            // the chart as it stands today. Reclassifying an account restates
            // every period this report covers, including ones already filed.
            'uses_current_categories' => true,
            'note' => 'Activities are taken from the cash flow categories the accounts hold now, '
                .'so reclassifying an account changes this report for every period it covers.',
            'cash_accounts' => $accounts->map(fn (Account $account): array => [
                'id' => (int) $account->getKey(),
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'is_cash' => (bool) $account->is_cash,
                'is_bank' => (bool) $account->is_bank,
            ])->values()->all(),
        ];
    }

    /**
     * What each account holds as at a date, and what they hold between them.
     *
     * @return array<string, mixed>
     */
    public function position(int $companyId, ?string $asOf = null): array
    {
        $accounts = $this->moneyAccounts($companyId);
        $asOf = $asOf ?? now()->toDateString();

        $balances = $this->ledger->accountBalances($companyId, $this->ids($accounts), $asOf);

        $rows = [];
        $total = Money::zero();

        foreach ($accounts as $account) {
            // Absent from the ledger's answer means it never moved, which is a
            // balance of zero rather than a missing account.
            $balance = $balances[(int) $account->getKey()] ?? Money::zero();
            $total = $total->plus($balance);

            $rows[] = [
                'account_id' => (int) $account->getKey(),
                'ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'is_cash' => (bool) $account->is_cash,
                'is_bank' => (bool) $account->is_bank,
                'cash_flow_category' => $account->cash_flow_category?->value,
                'balance' => $balance->toDecimal(),
            ];
        }

        return [
            'as_of' => $asOf,
            'total' => $total->toDecimal(),
            'accounts' => $rows,
        ];
    }

    /**
     * The accounts the company keeps money in, in code order.
     *
     * @return Collection<int, Account>
     */
    public function moneyAccounts(int $companyId): Collection
    {
        return Account::query()
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query->where('is_cash', true)->orWhere('is_bank', true))
            ->orderBy('code')
            ->get();
    }

    /**
     * The movements in the period, split into the three activities plus the two
     * figures a reader has to be told about separately.
     *
     * @param  array<int, int>  $accountIds
     * @return array{activities: list<array<string, mixed>>, internal_transfers: array<string, mixed>, unclassified: array<string, mixed>}
     */
    private function classify(int $companyId, array $accountIds, ?string $from, ?string $to): array
    {
        $totals = $this->counterTotals($companyId, $accountIds, $from, $to);

        $activities = [];

        foreach (CashFlowCategory::statementOrder() as $category) {
            $activities[] = $this->line($category->value, $category->label(), $totals[$category->value] ?? null);
        }

        return [
            'activities' => $activities,
            // Movements whose counter account is one of the company's own money
            // accounts. They are reported here and take no part in the three
            // activities, which is why the statement carries them separately:
            // net_movement has to account for every pound that entered or left,
            // and a figure nobody is shown is a figure nobody can check.
            'internal_transfers' => $this->line(
                CashFlowCategory::InternalTransfer->value,
                CashFlowCategory::InternalTransfer->label(),
                $totals[CashFlowCategory::InternalTransfer->value] ?? null,
            ),
            // Movements on an account nobody has classified. They are shown
            // rather than dropped, because a report that silently discards what
            // it cannot explain is worse than one that admits the gap — and
            // complete: false is what says so.
            'unclassified' => $this->line('unclassified', 'Unclassified', $totals['unclassified'] ?? null),
        ];
    }

    /**
     * Debit and credit totals for the counter side of the period's cash
     * movements, grouped by the category of the account on that side.
     *
     * The counter lines are read from the journals that touched a money account
     * in the window, and are not re-filtered by date: a journal's lines all
     * carry the journal's own posting date, so the window is already decided.
     * They are not re-filtered by status either, because the journal ids came
     * from a scan that tested it.
     *
     * @param  array<int, int>  $accountIds
     * @return array<string, object>
     */
    private function counterTotals(int $companyId, array $accountIds, ?string $from, ?string $to): array
    {
        if ($accountIds === []) {
            return [];
        }

        $journalIds = $this->ledger
            ->postedLinesFor($companyId, $accountIds, $from, $to)
            ->distinct()
            ->pluck('journal_lines.journal_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($journalIds === []) {
            return [];
        }

        return JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.company_id', $companyId)
            ->whereIn('journal_lines.journal_id', $journalIds)
            ->whereNotIn('journal_lines.account_id', $accountIds)
            ->selectRaw(
                'accounts.cash_flow_category AS category,'
                .' COALESCE(SUM(journal_lines.debit), 0) AS debit,'
                .' COALESCE(SUM(journal_lines.credit), 0) AS credit'
            )
            ->groupBy('accounts.cash_flow_category')
            ->get()
            ->keyBy(static fn (object $row): string => $row->category ?? 'unclassified')
            ->all();
    }

    /**
     * One activity's numbers, from the counter lines that carry its category.
     *
     * The credit side of a counter line is an inflow and the debit side an
     * outflow, which is not a convention invented here: a counter line balances
     * a cash line, so a credit to receivables is what a debit to the bank
     * answered, and money arriving is what the company received.
     *
     * @return array<string, mixed>
     */
    private function line(string $category, string $label, ?object $totals): array
    {
        $inflow = Money::of($totals === null ? '0' : (string) $totals->credit);
        $outflow = Money::of($totals === null ? '0' : (string) $totals->debit);

        return [
            'category' => $category,
            'label' => $label,
            'inflow' => $inflow->toDecimal(),
            'outflow' => $outflow->toDecimal(),
            'net' => $inflow->minus($outflow)->toDecimal(),
        ];
    }

    /**
     * @param  array<int, int>  $accountIds
     */
    private function totalCash(int $companyId, array $accountIds, ?string $asOf): Money
    {
        return Money::sum(array_values($this->ledger->accountBalances($companyId, $accountIds, $asOf)));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<int, int>
     */
    private function ids(Collection $accounts): array
    {
        return $accounts->map(static fn (Account $account): int => (int) $account->getKey())->all();
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }
}
