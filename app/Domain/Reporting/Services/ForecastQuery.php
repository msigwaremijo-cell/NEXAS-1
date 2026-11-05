<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Reporting\Exceptions\ForecastUnavailableException;
use App\Models\Account;
use App\Models\Budget;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The rest of the open fiscal year, projected period by period.
 *
 * Two sources meet here and each row says which one it came from. Where the
 * company wrote a plan for a period, the forecast is that plan to the cent:
 * a company that has said what it means to do is not second-guessed by an
 * average. Where it wrote no plan, the forecast is the trailing run-rate — the
 * average per period of what the account actually posted over the completed
 * periods of this same fiscal year before the current one, read from the
 * ledger through the same profit and loss statement as the reports.
 *
 * Nothing here is stored and nothing here posts. A forecast is derived on
 * demand from two things that already exist, so it cannot go stale and needs
 * no schema, no permission pair of its own and no audit entries. That is also
 * why a forecast figure is never an actual figure: the actual column comes from
 * FinancialStatementQuery and is the ledger, the forecast column is a
 * statement of intent, and the two are handed back side by side rather than
 * mixed.
 *
 * The horizon is deliberately bounded by the calendar. A forecast runs from the
 * current period to the last period of the open fiscal year and no further,
 * because a period that has not been opened is not a period the company has
 * said it will keep books for. Where the calendar cannot answer at all — no
 * periods, no period covering the date, no open year — the report refuses
 * rather than inventing one.
 *
 * Past periods of the year are included, marked not projected, so the whole
 * year can be read in one payload. Nothing is projected onto them: what
 * happened is a fact and does not need a forecast.
 */
final class ForecastQuery
{
    public function __construct(private readonly FinancialStatementQuery $statements) {}

    /**
     * @return array<string, mixed>
     */
    public function forecast(int $companyId, ?string $asOf = null): array
    {
        $asOf = $asOf ?? now()->toDateString();

        $current = $this->currentPeriod($companyId, $asOf);
        $year = $this->openYear($companyId, $current);

        $periods = FiscalPeriod::query()
            ->where('fiscal_year_id', $year->getKey())
            ->orderBy('period_no')
            ->get();

        $horizon = $periods->filter(
            static fn (FiscalPeriod $period): bool => $period->period_no >= $current->period_no
        );

        $lookback = $periods->filter(
            static fn (FiscalPeriod $period): bool => $period->period_no < $current->period_no
        )->values();

        [$rate, $lookbackWindow] = $this->runRate($companyId, $lookback);
        $budgets = $this->budgets($companyId, $horizon->pluck('id')->all());

        // First pass: the figures, per period, with every account the period
        // has anything to say about. The labels are read afterwards for the
        // whole year in one query rather than once per period.
        $raw = [];
        $accountIds = [];

        foreach ($periods as $period) {
            $projected = $period->period_no >= $current->period_no;
            $actuals = $this->accountAmounts($this->statements->profitAndLoss(
                $companyId,
                $period->starts_on->toDateString(),
                $period->ends_on->toDateString(),
            ));

            $planned = $this->planned($projected ? $budgets->get((int) $period->getKey()) : null);

            $ids = $projected
                ? array_values(array_unique([...array_keys($planned), ...array_keys($rate), ...array_keys($actuals)]))
                : array_keys($actuals);

            $rows = [];

            foreach ($ids as $id) {
                $actual = $actuals[$id] ?? Money::zero();
                [$forecast, $source] = $projected ? $this->basis($id, $planned, $rate) : [null, null];

                // An account with nothing planned and nothing posted has
                // nothing to say, in a forecast no more than in a statement.
                if ($actual->isZero() && ($forecast === null || $forecast->isZero())) {
                    continue;
                }

                $rows[$id] = ['actual' => $actual, 'forecast' => $forecast, 'source' => $source];
            }

            $accountIds = [...$accountIds, ...array_keys($rows)];

            $raw[] = [
                'period' => $period,
                'projected' => $projected,
                // Whether the company wrote a plan for this period at all. A
                // plan of nothing is still a plan, so this is asked of the
                // budget itself rather than inferred from the amounts.
                'has_plan' => $projected && $budgets->has((int) $period->getKey()),
                'rows' => $rows,
            ];
        }

        $accounts = $this->accounts($companyId, $accountIds);

        // Second pass: the same figures, now labelled and split by section.
        $blocks = [];
        $projectedRevenue = [];
        $projectedExpense = [];
        $projectedBudgetRevenue = [];
        $projectedBudgetExpense = [];

        foreach ($raw as $entry) {
            $period = $entry['period'];
            $revenue = [];
            $expense = [];

            foreach ($entry['rows'] as $id => $row) {
                $account = $accounts->get((int) $id);

                if ($account === null) {
                    continue;
                }

                $line = [
                    'account_id' => (int) $id,
                    'account_ulid' => $account->ulid,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type->value,
                    'actual' => $row['actual']->toDecimal(),
                    'forecast' => $row['forecast']?->toDecimal(),
                    'source' => $row['source'],
                ];

                if ($account->account_type === AccountType::Revenue) {
                    $revenue[] = $line;
                } else {
                    $expense[] = $line;
                }
            }

            $revenueTotals = $this->totals($revenue, $entry['projected']);
            $expenseTotals = $this->totals($expense, $entry['projected']);

            // The plan the period was projected from, kept apart from the
            // projection itself: a reader comparing the two needs the plan as
            // the company wrote it, not as the projection restates it. An
            // account is on the plan when its row says so, so a plan of nothing
            // contributes nothing — which is the truth about it.
            $plan = $entry['has_plan']
                ? $this->planTotals($revenue, $expense)
                : ['revenue' => null, 'expense' => null, 'net' => null];

            if ($entry['projected']) {
                $projectedRevenue[] = $revenueTotals;
                $projectedExpense[] = $expenseTotals;
                $projectedBudgetRevenue[] = ['budget' => $plan['revenue'] ?? '0.0000'];
                $projectedBudgetExpense[] = ['budget' => $plan['expense'] ?? '0.0000'];
            }

            $blocks[] = [
                'period' => [
                    'ulid' => $period->ulid,
                    'name' => $period->name,
                    'period_no' => (int) $period->period_no,
                    'starts_on' => $period->starts_on->toDateString(),
                    'ends_on' => $period->ends_on->toDateString(),
                    'status' => $period->status->value,
                ],
                // False for a period that has already happened: it is reported,
                // because a year is read as a whole, but nothing is projected
                // onto it. Its forecast columns above are null for that reason.
                'is_forecast' => $entry['projected'],
                'has_plan' => $entry['has_plan'],
                'budget' => $plan,
                'revenue' => ['accounts' => $revenue] + $revenueTotals,
                'expense' => ['accounts' => $expense] + $expenseTotals,
                'net' => $this->net($revenueTotals, $expenseTotals),
            ];
        }

        $first = $horizon->first();
        $last = $horizon->last();

        return [
            'as_of' => $asOf,
            'fiscal_year' => [
                'ulid' => $year->ulid,
                'name' => $year->name,
                'starts_on' => $year->starts_on->toDateString(),
                'ends_on' => $year->ends_on->toDateString(),
                'status' => $year->status->value,
            ],
            'current_period' => [
                'ulid' => $current->ulid,
                'name' => $current->name,
                'period_no' => (int) $current->period_no,
                'starts_on' => $current->starts_on->toDateString(),
                'ends_on' => $current->ends_on->toDateString(),
            ],
            'horizon' => [
                'from_period' => $first?->ulid,
                'from_period_name' => $first?->name,
                'to_period' => $last?->ulid,
                'to_period_name' => $last?->name,
                'period_count' => $horizon->count(),
                'ends_on' => $last?->ends_on?->toDateString(),
            ],
            'basis' => 'budget where the company wrote a plan for the period, otherwise the trailing run-rate',
            'run_rate' => [
                'used' => $lookback->isNotEmpty(),
                'basis' => 'average per period of what the account actually posted',
                'from' => $lookbackWindow['from'],
                'to' => $lookbackWindow['to'],
                'period_count' => $lookback->count(),
                'periods' => $lookback->map(static fn (FiscalPeriod $period): string => $period->name)->all(),
            ],
            'assumptions' => $this->assumptions(),
            'periods' => $blocks,
            'horizon_totals' => [
                'revenue' => [
                    'actual' => $this->sumOf($projectedRevenue, 'actual'),
                    'forecast' => $this->sumOf($projectedRevenue, 'forecast'),
                ],
                'expense' => [
                    'actual' => $this->sumOf($projectedExpense, 'actual'),
                    'forecast' => $this->sumOf($projectedExpense, 'forecast'),
                ],
                'net' => [
                    'actual' => Money::of($this->sumOf($projectedRevenue, 'actual'))
                        ->minus(Money::of($this->sumOf($projectedExpense, 'actual')))->toDecimal(),
                    'forecast' => Money::of($this->sumOf($projectedRevenue, 'forecast'))
                        ->minus(Money::of($this->sumOf($projectedExpense, 'forecast')))->toDecimal(),
                ],
                // The plan the horizon was projected from, totalled. Read from
                // the `budget` rows alone, so a period with no plan adds
                // nothing and the figure compares the projection with the plan
                // rather than with itself.
                'budget' => [
                    'revenue' => $this->sumOf($projectedBudgetRevenue, 'budget'),
                    'expense' => $this->sumOf($projectedBudgetExpense, 'budget'),
                    'net' => Money::of($this->sumOf($projectedBudgetRevenue, 'budget'))
                        ->minus(Money::of($this->sumOf($projectedBudgetExpense, 'budget')))->toDecimal(),
                ],
            ],
            'forecast_is_not_actual' => true,
            'note' => 'The forecast column is a projection and not a transaction: nothing here is stored, '
                .'no journal is posted, and no forecast figure appears in a trial balance or any other '
                .'actual report. The actual column is the same posted ledger the profit and loss '
                .'statement reads. A period the company wrote a plan for is projected at that plan; a '
                .'period it did not is projected at the trailing run-rate. Each account row names the '
                .'basis it was taken from. A period already past carries no forecast at all: it is '
                .'reported as it happened. Only periods that exist in the open fiscal year are '
                .'projected, up to its last one.',
        ];
    }

    /**
     * The average per period an account posted over the completed periods of
     * this fiscal year before the current one.
     *
     * The completed periods are contiguous, so one read over their whole span
     * is the same as reading each and adding them up — and it is read through
     * the profit and loss statement, so the sign convention and the rule about
     * which journals count match the reports exactly.
     *
     * @param  Collection<int, FiscalPeriod>  $lookback
     * @return array{0: array<int, Money>, 1: array{from: ?string, to: ?string}}
     */
    private function runRate(int $companyId, Collection $lookback): array
    {
        $from = $lookback->first()?->starts_on?->toDateString();
        $to = $lookback->last()?->ends_on?->toDateString();

        if ($from === null || $to === null) {
            return [[], ['from' => null, 'to' => null]];
        }

        $count = $lookback->count();
        $rate = [];

        foreach ($this->accountAmounts($this->statements->profitAndLoss($companyId, $from, $to)) as $id => $amount) {
            $rate[$id] = $amount->dividedBy((string) $count);
        }

        return [$rate, ['from' => $from, 'to' => $to]];
    }

    /**
     * Which of the two bases a period's figure for an account came from.
     *
     * A plan that says zero is still a plan, so the test is whether the company
     * planned the account at all rather than whether the amount is non-zero.
     * Otherwise an account deliberately planned at nothing would silently fall
     * through to an average of what it used to do, which is the opposite of
     * what the company said.
     *
     * @param  array<int, Money>  $planned
     * @param  array<int, Money>  $rate
     * @return array{0: Money, 1: string}
     */
    private function basis(int $id, array $planned, array $rate): array
    {
        if (array_key_exists($id, $planned)) {
            return [$planned[$id], 'budget'];
        }

        if (array_key_exists($id, $rate)) {
            return [$rate[$id], 'run_rate'];
        }

        return [Money::zero(), 'none'];
    }

    private function currentPeriod(int $companyId, string $asOf): FiscalPeriod
    {
        $periods = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->orderBy('starts_on')
            ->get();

        if ($periods->isEmpty()) {
            throw ForecastUnavailableException::noCalendar($companyId);
        }

        $current = $periods->first(
            static fn (FiscalPeriod $period): bool => $period->containsDate($asOf)
        );

        if ($current === null) {
            throw ForecastUnavailableException::noCurrentPeriod($companyId, $asOf);
        }

        return $current;
    }

    private function openYear(int $companyId, FiscalPeriod $current): FiscalYear
    {
        $year = FiscalYear::query()
            ->where('company_id', $companyId)
            ->whereKey($current->fiscal_year_id)
            ->first();

        if ($year === null) {
            throw ForecastUnavailableException::noCalendar($companyId);
        }

        if (! $year->acceptsPostings()) {
            throw ForecastUnavailableException::yearNotOpen($year);
        }

        return $year;
    }

    /**
     * The plan for every projected period, keyed by the period it belongs to.
     * One query for the whole horizon rather than one per period.
     *
     * @param  list<int|string>  $periodIds
     * @return Collection<int, Budget>
     */
    private function budgets(int $companyId, array $periodIds): Collection
    {
        if ($periodIds === []) {
            return collect();
        }

        return Budget::query()
            ->where('company_id', $companyId)
            ->whereIn('fiscal_period_id', $periodIds)
            ->with('lines')
            ->get()
            ->keyBy(static fn (Budget $budget): int => (int) $budget->fiscal_period_id);
    }

    /**
     * @return array<int, Money>
     */
    private function planned(?Budget $budget): array
    {
        $planned = [];

        foreach ($budget?->lines ?? [] as $line) {
            $id = (int) $line->account_id;
            $planned[$id] = ($planned[$id] ?? Money::zero())
                ->plus(Money::of((string) $line->amount));
        }

        return $planned;
    }

    /**
     * A statement's own figures by account, in its sign convention: revenue and
     * expense both positive on their own side.
     *
     * @param  array<string, mixed>  $statement
     * @return array<int, Money>
     */
    private function accountAmounts(array $statement): array
    {
        $amounts = [];

        foreach (['revenue', 'expense'] as $section) {
            foreach ($statement[$section]['accounts'] as $row) {
                $amounts[(int) $row['account_id']] = Money::of((string) $row['amount']);
            }
        }

        return $amounts;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Account>
     */
    private function accounts(int $companyId, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return collect();
        }

        return Account::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->orderBy('code')
            ->get()
            ->keyBy(static fn (Account $account): int => (int) $account->getKey());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{actual: string, forecast: ?string}
     */
    private function totals(array $rows, bool $projected): array
    {
        return [
            'actual' => $this->sumOf($rows, 'actual'),
            // Null rather than zero on a period already past, because a period
            // that has happened was not projected at all and a zero would read
            // as a forecast of nothing.
            'forecast' => $projected ? $this->sumOf($rows, 'forecast') : null,
        ];
    }

    /**
     * @param  array{actual: string, forecast: ?string}  $revenue
     * @param  array{actual: string, forecast: ?string}  $expense
     * @return array{actual: string, forecast: ?string}
     */
    private function net(array $revenue, array $expense): array
    {
        return [
            'actual' => Money::of($revenue['actual'])->minus(Money::of($expense['actual']))->toDecimal(),
            'forecast' => $revenue['forecast'] === null
                ? null
                : Money::of($revenue['forecast'])->minus(Money::of($expense['forecast']))->toDecimal(),
        ];
    }

    /**
     * The plan a period was projected from, as the company wrote it.
     *
     * Only the rows the projection took from the plan are counted, so what is
     * returned is the plan itself and not a restatement of the projection:
     * an account the company did not plan contributes nothing, and its row is
     * marked `run_rate` for that reason. The caller only asks when a plan
     * exists, so a plan of nothing reads as zero rather than as a missing
     * figure.
     *
     * @param  list<array<string, mixed>>  $revenue
     * @param  list<array<string, mixed>>  $expense
     * @return array{revenue: string, expense: string, net: string}
     */
    private function planTotals(array $revenue, array $expense): array
    {
        $plannedRevenue = $this->sumOf($this->onPlan($revenue), 'forecast');
        $plannedExpense = $this->sumOf($this->onPlan($expense), 'forecast');

        return [
            'revenue' => $plannedRevenue,
            'expense' => $plannedExpense,
            'net' => Money::of($plannedRevenue)->minus(Money::of($plannedExpense))->toDecimal(),
        ];
    }

    /**
     * The rows a period took from its plan rather than from the run-rate.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function onPlan(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['source'] ?? null) === 'budget',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sumOf(array $rows, string $key): string
    {
        return Money::sum(array_map(
            static fn (array $row): Money => Money::of((string) $row[$key]),
            $rows,
        ))->toDecimal();
    }

    /**
     * What the projection assumes, said out loud in the payload rather than
     * left for a reader to infer from the numbers.
     *
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'A period the company wrote a plan for is projected at that plan, to the cent. Where it '
                .'wrote a plan, its own statement of intent is not second-guessed by an average.',
            'A period with no plan is projected at the trailing run-rate: the average per period of '
                .'what the account actually posted over the completed periods of this fiscal year '
                .'before the current one.',
            'The run-rate is read from the posted ledger through the same profit and loss statement '
                .'as the reports, and divided by the number of completed periods, so a fiscal year '
                .'only half behind the company is not read as if it were slowing down.',
            'Only periods that already exist in the open fiscal year are projected, and no further '
                .'than its last one. A period the company has not opened is not a period it has said '
                .'it will keep books for.',
            'Revenue and expense are projected. Nothing is placed on the balance sheet: when a '
                .'receivable will be collected cannot be read from the ledger, so it would be '
                .'invented.',
            'A forecast is intent and not a transaction. Nothing here is stored, no journal is '
                .'posted, and no forecast figure enters any actual report.',
        ];
    }
}
