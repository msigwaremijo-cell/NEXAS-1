<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Domain\Accounting\Enums\JournalType;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the company's money came from and where it went.
 *
 * Three reports, built from one rule. `position()` says what is in each account
 * as at a date; `statement()` says how the total moved over a period;
 * `statementIndirect()` says the same movement the other way round, from the
 * profit. They share the definition of which accounts hold the money, so the
 * closing figure of one and the total of the others are the same figure read
 * twice, and tests assert they agree.
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
    public function __construct(
        private readonly LedgerQuery $ledger,
        private readonly PeriodComparison $periods,
    ) {}

    /**
     * The cash flow statement for a period, with an optional comparative column.
     *
     * `from` is inclusive, and opening cash is what the accounts stood at the
     * day before it, so a statement run for March does not count February's
     * closing movement twice. Omitting both dates reports the whole ledger,
     * opening at zero.
     *
     * $compare asks for a prior column: the same statement read over the window
     * of equal length immediately before this one, so the two columns are one
     * report read twice rather than two figures computed different ways. Which
     * window "the previous period" means is decided in one place,
     * PeriodComparison, and not here. A variance is the prior column taken off
     * the current one, so a positive number is always an increase. Comparing a
     * whole-ledger report is meaningless — there is no window before all of it —
     * so a report with no dates stays single-column however $compare is set.
     *
     * @return array<string, mixed>
     */
    public function statement(
        int $companyId,
        ?string $from = null,
        ?string $to = null,
        bool $compare = false,
    ): array {
        $body = $this->statementBody($companyId, $from, $to);

        if (! $compare || $from === null || $to === null) {
            return $body + ['comparison' => null, 'variance' => null];
        }

        [$priorFrom, $priorTo] = $this->periods->precedingWindow($from, $to);

        $prior = $this->statementBody($companyId, $priorFrom, $priorTo);

        return $body + [
            'comparison' => $prior,
            'variance' => $this->variance($body, $prior),
        ];
    }

    /**
     * The same period's money, read the indirect way: down from the profit
     * rather than across the activities.
     *
     * The direct statement above asks what each counter account was; this one
     * asks what the profit and the balance sheet did. It opens on the period's
     * net profit and then adds back, one line per balance sheet account, the
     * movement in every account that is not itself one of the money accounts.
     * An asset that grew by its movement used that much money and is subtracted;
     * a liability that grew by it provided that much and is added. Nothing here
     * is a convention: it is the ledger's own debit-minus-credit read the other
     * way round, which is why an adjustment and the movement it describes are
     * never two figures that could disagree.
     *
     * The total is not a second opinion on the period. Every journal balances,
     * and every line of a journal carries the journal's own posting date, so
     * over any window the movements of all the accounts sum to zero: whatever
     * the money accounts gained, the profit and the other balances gave up
     * between them. So net profit plus the adjustments is the same figure the
     * direct statement reaches by a different route, and a test asserts the two
     * agree rather than trusting the paragraph.
     *
     * Closing entries are left out on both sides. See movementBetween() for why
     * they are outside a period's trading: they carry the closed year's own last
     * day, so counting them would take the very year being reported back to
     * nothing. Leaving them out of the profit and out of the equity adjustments
     * together leaves the total unmoved, because the entry the closing makes is
     * a movement between two accounts that are both excluded here.
     *
     * $compare asks for a prior column, over the same window the direct
     * statement compares against, so the two reports' prior columns describe one
     * period rather than two. Which window that is lives in PeriodComparison and
     * not here.
     *
     * @return array<string, mixed>
     */
    public function statementIndirect(
        int $companyId,
        ?string $from = null,
        ?string $to = null,
        bool $compare = false,
    ): array {
        $body = $this->indirectBody($companyId, $from, $to);

        if (! $compare || $from === null || $to === null) {
            return $body + ['comparison' => null, 'variance' => null];
        }

        [$priorFrom, $priorTo] = $this->periods->precedingWindow($from, $to);

        $prior = $this->indirectBody($companyId, $priorFrom, $priorTo);

        return $body + [
            'comparison' => $prior,
            'variance' => $this->indirectVariance($body, $prior),
        ];
    }

    /**
     * One indirect statement over one window, before any comparison is added.
     *
     * @return array<string, mixed>
     */
    private function indirectBody(int $companyId, ?string $from, ?string $to): array
    {
        $netProfit = $this->netProfit($companyId, $from, $to);

        $accounts = $this->adjustmentAccounts($companyId);

        $movements = $this->ledger->movementBetween(
            $companyId,
            $this->ids($accounts),
            $from,
            $to,
            [JournalType::Closing->value],
        );

        /** @var array<string, list<array<string, mixed>>> $lines */
        $lines = [];

        foreach (self::bucketOrder() as $key) {
            $lines[$key] = [];
        }

        foreach ($accounts as $account) {
            $movement = $movements[(int) $account->getKey()] ?? Money::zero();

            // An account that never moved, or whose movements cancelled out
            // inside the window, has nothing to add back.
            if ($movement->isZero()) {
                continue;
            }

            $lines[$this->bucketKey($account)][] = [
                'account_id' => (int) $account->getKey(),
                'account_ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                // The ledger reads debit minus credit. Its opposite is the
                // movement's effect on cash, so the sign is flipped once, here,
                // and the reader never has to know which side the account is on.
                'amount' => $movement->negated()->toDecimal(),
            ];
        }

        $adjustments = [];
        $netAdjustments = Money::zero();

        foreach (self::bucketOrder() as $key) {
            $total = Money::sum(array_map(
                static fn (array $row): Money => Money::of((string) $row['amount']),
                $lines[$key],
            ));

            $netAdjustments = $netAdjustments->plus($total);

            $adjustments[] = [
                'category' => $key,
                'label' => self::bucketLabel($key),
                'accounts' => $lines[$key],
                'total' => $total->toDecimal(),
            ];
        }

        ['opening' => $opening, 'closing' => $closing] = $this->cashBalances(
            $companyId,
            $this->ids($this->moneyAccounts($companyId)),
            $from,
            $to,
        );

        $net = $netProfit->plus($netAdjustments);

        return [
            'from' => $from,
            'to' => $to,
            'net_profit' => $netProfit->toDecimal(),
            'adjustments' => $adjustments,
            'net_adjustments' => $netAdjustments->toDecimal(),
            'net_movement' => $net->toDecimal(),
            'opening_cash' => $opening->toDecimal(),
            'closing_cash' => $closing->toDecimal(),
            // Profit plus the adjustments, against a closing figure read
            // independently out of the ledger. The direct statement makes the
            // same claim by another route; a test asserts the two totals agree.
            'balanced' => $opening->plus($net)->equals($closing),
            // True when every balance sheet movement this statement reads was
            // on an account that carries a category. An account nobody has
            // classified is shown on its own line rather than dropped, and this
            // is what says the report could not place everything it read.
            'complete' => $lines['unclassified'] === [],
            'uses_current_categories' => true,
            'note' => 'The same period as the direct statement, read from the profit: each line is the '
                .'movement in one balance sheet account that is not itself a money account, with its '
                .'sign flipped, so the total is the direct statement\'s total by construction.',
        ];
    }

    /**
     * One statement over one window, before any comparison is added.
     *
     * @return array<string, mixed>
     */
    private function statementBody(int $companyId, ?string $from, ?string $to): array
    {
        $accounts = $this->moneyAccounts($companyId);
        $accountIds = $this->ids($accounts);

        ['opening' => $opening, 'closing' => $closing] = $this->cashBalances($companyId, $accountIds, $from, $to);

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
     * The current column less the prior one, activity by activity and in total.
     *
     * A positive figure is always an increase, the same sign convention the
     * other comparative statements use, so a movement that grew reads as a
     * positive variance whichever activity it belongs to.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $prior
     * @return array<string, string>
     */
    private function variance(array $body, array $prior): array
    {
        $variance = [];

        foreach (CashFlowCategory::statementOrder() as $category) {
            $variance[$category->value] = $this->activityNet($body, $category)
                ->minus($this->activityNet($prior, $category))
                ->toDecimal();
        }

        $variance['net_movement'] = Money::of((string) $body['net_movement'])
            ->minus(Money::of((string) $prior['net_movement']))
            ->toDecimal();

        return $variance;
    }

    /**
     * One activity's net figure out of a statement body.
     *
     * @param  array<string, mixed>  $body
     */
    private function activityNet(array $body, CashFlowCategory $category): Money
    {
        /** @var list<array<string, mixed>> $activities */
        $activities = $body['activities'];

        foreach ($activities as $line) {
            if (($line['category'] ?? null) === $category->value) {
                return Money::of((string) $line['net']);
            }
        }

        return Money::zero();
    }

    /**
     * The period's profit, as the profit and loss statement reads it, so the
     * figure the indirect statement opens on is the figure the statement beside
     * it reports and not a second one computed another way.
     *
     * Revenue is credit-normal, so over the temporary accounts the ledger's
     * debit-minus-credit sums to expense less revenue — the profit the other way
     * round. Negating it puts a profit up as a positive figure and a loss as a
     * negative one. Closing entries are excluded for the same reason the profit
     * and loss report excludes them: they carry the closed year's own last day.
     */
    private function netProfit(int $companyId, ?string $from, ?string $to): Money
    {
        $accounts = $this->accountsOfType($companyId, [AccountType::Revenue, AccountType::Expense]);

        $movements = $this->ledger->movementBetween(
            $companyId,
            $this->ids($accounts),
            $from,
            $to,
            [JournalType::Closing->value],
        );

        return Money::sum(array_values($movements))->negated();
    }

    /**
     * The balance sheet accounts a movement is added back for: everything that
     * holds a balance between two year ends and is not itself one of the money
     * accounts. Revenue and expense are absent because the profit they make is
     * the statement's opening line, not an adjustment to it.
     *
     * @return Collection<int, Account>
     */
    private function adjustmentAccounts(int $companyId): Collection
    {
        return $this->accountsOfType($companyId, [
            AccountType::Asset,
            AccountType::Liability,
            AccountType::Equity,
        ])->reject(static fn (Account $account): bool => (bool) $account->is_cash || (bool) $account->is_bank);
    }

    /**
     * The company's accounts of the given types, in code order, keyed by id.
     *
     * Read withTrashed on purpose, for the same reason the statements read it
     * that way: an account taken out of the chart listing still has lines in the
     * ledger, and leaving it out would not make the movement smaller, it would
     * stop the statement agreeing with the money that actually moved.
     *
     * @param  array<int, AccountType>  $types
     * @return Collection<int, Account>
     */
    private function accountsOfType(int $companyId, array $types): Collection
    {
        return Account::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('account_type', array_map(
                static fn (AccountType $type): string => $type->value,
                $types,
            ))
            ->orderBy('code')
            ->get()
            ->keyBy(static fn (Account $account): int => (int) $account->getKey());
    }

    /**
     * Which block an account's adjustment belongs in.
     *
     * The category is the account's own, the same one the direct statement
     * classifies counter lines by, so the two readings of the period agree about
     * what is operating and what is not. An account nobody has classified is
     * its own block rather than being folded into operating, because a report
     * that invents a home for what it cannot place is worse than one that says
     * it could not.
     */
    private function bucketKey(Account $account): string
    {
        return $account->cash_flow_category?->value ?? 'unclassified';
    }

    /**
     * The blocks the adjustment lines are grouped into, in reading order.
     *
     * @return list<string>
     */
    private static function bucketOrder(): array
    {
        return ['operating', 'investing', 'financing', 'internal_transfer', 'unclassified'];
    }

    private static function bucketLabel(string $key): string
    {
        $category = CashFlowCategory::tryFrom($key);

        return $category === null ? 'Unclassified' : $category->label();
    }

    /**
     * The current column less the prior one, activity by activity and in total.
     *
     * A positive figure is always an increase, the same sign convention the
     * direct statement's variance uses, so the two comparative columns read the
     * same way.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $prior
     * @return array<string, string>
     */
    private function indirectVariance(array $body, array $prior): array
    {
        $variance = [];

        foreach (CashFlowCategory::statementOrder() as $category) {
            $variance[$category->value] = $this->adjustmentTotal($body, $category->value)
                ->minus($this->adjustmentTotal($prior, $category->value))
                ->toDecimal();
        }

        $variance['net_profit'] = Money::of((string) $body['net_profit'])
            ->minus(Money::of((string) $prior['net_profit']))
            ->toDecimal();

        $variance['net_movement'] = Money::of((string) $body['net_movement'])
            ->minus(Money::of((string) $prior['net_movement']))
            ->toDecimal();

        return $variance;
    }

    /**
     * One adjustment block's total out of a statement body.
     *
     * @param  array<string, mixed>  $body
     */
    private function adjustmentTotal(array $body, string $category): Money
    {
        /** @var list<array<string, mixed>> $adjustments */
        $adjustments = $body['adjustments'];

        foreach ($adjustments as $block) {
            if (($block['category'] ?? null) === $category) {
                return Money::of((string) $block['total']);
            }
        }

        return Money::zero();
    }

    /**
     * The pool's standing at the two ends of a window: the day before it opens,
     * and its last day.
     *
     * Opening and closing are read here rather than written out at the call
     * site so that the statement's closing figure and the position report's
     * total are the same sum over the same accounts read the same way, which is
     * what a test asserts when it says the two reports agree. A window with no
     * lower bound reports the whole ledger from zero, because there is no day
     * before the beginning of everything.
     *
     * @param  array<int, int>  $accountIds
     * @return array{opening: Money, closing: Money}
     */
    private function cashBalances(int $companyId, array $accountIds, ?string $from, ?string $to): array
    {
        return [
            'opening' => $from === null
                ? Money::zero()
                : $this->totalCash($companyId, $accountIds, $this->dayBefore($from)),
            'closing' => $this->totalCash($companyId, $accountIds, $to),
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
