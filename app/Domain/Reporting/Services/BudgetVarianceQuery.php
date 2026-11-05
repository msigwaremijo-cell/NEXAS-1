<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\FiscalPeriod;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * The plan for a period beside what the period actually did.
 *
 * The two columns come from two different places and are deliberately read in
 * one report rather than merged anywhere. The budget column is the company's
 * own statement of intent — a plan, and never a transaction: no journal is
 * written when a plan is set, so the ledger does not know the plan exists. The
 * actual column is the period's posted ledger totals, read through
 * FinancialStatementQuery, which is the same statement the profit and loss
 * report uses. Building the actual side any other way would risk it disagreeing
 * with the profit and loss beside it, and a variance report whose actual column
 * is not the ledger is worse than no report at all.
 *
 * A plan line is written per account and per cost centre, but the ledger
 * carries no cost centre column, so "actual by cost centre" cannot be answered
 * without inventing a figure. The report therefore reads by account: the
 * company's plan lines for an account are summed across their cost centres and
 * compared with that account's ledger total. Cost centres stay where they can
 * be honest — on the plan itself.
 *
 * A plan line that has no movement beside it is still shown, because that is
 * the most useful thing a variance report says: an account the company planned
 * to spend on and has not touched is exactly what a reader is looking for.
 *
 * The variance is the actual less the budget, the same convention the
 * comparative statements use — a positive figure is always the actual above the
 * plan. On revenue that is favourable and on expense it is not, which the note
 * says out loud rather than leaving the reader to work out.
 *
 * The percentage is null rather than infinite when the budget is zero: a
 * variance of any size against a plan of nothing has no percentage, and saying
 * so is better than dividing by zero or inventing a figure.
 */
final class BudgetVarianceQuery
{
    public function __construct(
        private readonly FinancialStatementQuery $statements,
        private readonly PeriodComparison $periods,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(int $companyId, FiscalPeriod $period, bool $compare = false): array
    {
        $body = $this->body($companyId, $period);

        if (! $compare) {
            return $body + ['comparison' => null, 'variance' => null];
        }

        $prior = $this->priorPeriod($companyId, $period);

        if ($prior === null) {
            return $body + ['comparison' => null, 'variance' => null];
        }

        $priorBody = $this->body($companyId, $prior);

        return $body + [
            'comparison' => $priorBody,
            'variance' => [
                'revenue' => $this->difference($body['revenue'], $priorBody['revenue']),
                'expense' => $this->difference($body['expense'], $priorBody['expense']),
                'net' => $this->difference($body['net'], $priorBody['net']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $companyId, FiscalPeriod $period): array
    {
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        $statement = $this->statements->profitAndLoss($companyId, $from, $to);
        $budget = $this->budget($companyId, $period);

        $planned = $this->planned($budget);
        $actual = $this->actual($statement);
        $accounts = $this->accounts($companyId, array_keys($planned + $actual));

        $revenue = [];
        $expense = [];

        foreach ($accounts as $account) {
            $id = (int) $account->getKey();
            $budgeted = $planned[$id] ?? Money::zero();
            $posted = $actual[$id] ?? Money::zero();

            // An account neither planned nor moved has nothing to say.
            if ($budgeted->isZero() && $posted->isZero()) {
                continue;
            }

            $row = [
                'account_id' => $id,
                'account_ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'budget' => $budgeted->toDecimal(),
                'actual' => $posted->toDecimal(),
                'variance' => $posted->minus($budgeted)->toDecimal(),
                'variance_percent' => $this->percent($posted, $budgeted),
            ];

            if ($account->account_type === AccountType::Revenue) {
                $revenue[] = $row;
            } else {
                $expense[] = $row;
            }
        }

        $revenueTotals = $this->totals($revenue);
        $expenseTotals = $this->totals($expense);

        return [
            'period' => [
                'ulid' => $period->ulid,
                'name' => $period->name,
                'period_no' => (int) $period->period_no,
                'starts_on' => $from,
                'ends_on' => $to,
            ],
            // Null when the company has not written a plan for this period at
            // all, which is worth saying: the actuals below are still the
            // ledger's, and every variance is then the whole of the actual.
            'budget_ulid' => $budget?->ulid,
            'budget_name' => $budget?->name,
            'revenue' => ['accounts' => $revenue] + $revenueTotals,
            'expense' => ['accounts' => $expense] + $expenseTotals,
            'net' => $this->net($revenueTotals, $expenseTotals),
            'budget_is_a_plan' => true,
            'variance_basis' => 'actual_minus_budget',
            'note' => 'The budget column is the plan the company wrote for this period. It is a plan '
                .'and not a transaction: writing one posts no journal, so it appears in no trial '
                .'balance and the ledger does not know it exists. The actual column is the period\'s '
                .'posted ledger, read through the same profit and loss statement as the report beside '
                .'this one. The variance is the actual less the budget, so a positive figure is above '
                .'plan — favourable on revenue and unfavourable on expense. A percentage is absent '
                .'when the budget is zero, because a variance against a plan of nothing has no '
                .'percentage to state.',
        ];
    }

    /**
     * The plan the company wrote for the period, or null when it wrote none.
     */
    private function budget(int $companyId, FiscalPeriod $period): ?Budget
    {
        return Budget::query()
            ->where('company_id', $companyId)
            ->where('fiscal_period_id', $period->getKey())
            ->with('lines')
            ->first();
    }

    /**
     * The plan's amounts by account, summed across the cost centres the lines
     * were split into, because the ledger has no cost centre to compare with.
     *
     * @return array<int, Money>
     */
    private function planned(?Budget $budget): array
    {
        $planned = [];

        foreach ($budget->lines ?? [] as $line) {
            $id = (int) $line->account_id;
            $planned[$id] = ($planned[$id] ?? Money::zero())
                ->plus(Money::of((string) $line->amount));
        }

        return $planned;
    }

    /**
     * The ledger's own figures, keyed by account. Sign convention is the
     * statement's: revenue and expense both positive on their own side.
     *
     * @param  array<string, mixed>  $statement
     * @return array<int, Money>
     */
    private function actual(array $statement): array
    {
        $actual = [];

        foreach (['revenue', 'expense'] as $section) {
            foreach ($statement[$section]['accounts'] as $row) {
                $actual[(int) $row['account_id']] = Money::of((string) $row['amount']);
            }
        }

        return $actual;
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Account>
     */
    private function accounts(int $companyId, array $ids): Collection
    {
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
     * @return array{budget: string, actual: string, variance: string, variance_percent: ?string}
     */
    private function totals(array $rows): array
    {
        $budget = Money::sum(array_map(
            static fn (array $row): Money => Money::of((string) $row['budget']),
            $rows,
        ));

        $actual = Money::sum(array_map(
            static fn (array $row): Money => Money::of((string) $row['actual']),
            $rows,
        ));

        return [
            'budget' => $budget->toDecimal(),
            'actual' => $actual->toDecimal(),
            'variance' => $actual->minus($budget)->toDecimal(),
            'variance_percent' => $this->percent($actual, $budget),
        ];
    }

    /**
     * Revenue less expense, as amounts and as a percentage of the planned net.
     *
     * @param  array{budget: string, actual: string, variance: string, variance_percent: ?string}  $revenue
     * @param  array{budget: string, actual: string, variance: string, variance_percent: ?string}  $expense
     * @return array{budget: string, actual: string, variance: string, variance_percent: ?string}
     */
    private function net(array $revenue, array $expense): array
    {
        $planned = Money::of($revenue['budget'])->minus(Money::of($expense['budget']));
        $actual = Money::of($revenue['actual'])->minus(Money::of($expense['actual']));

        return [
            'budget' => $planned->toDecimal(),
            'actual' => $actual->toDecimal(),
            'variance' => $actual->minus($planned)->toDecimal(),
            'variance_percent' => $this->percent($actual, $planned),
        ];
    }

    /**
     * The prior column's figures taken off this column's, so a positive number
     * is an increase. Only the three totals are compared: the account rows of
     * two different periods are not the same list.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $prior
     * @return array<string, string>
     */
    private function difference(array $current, array $prior): array
    {
        return [
            'budget' => Money::of((string) $current['budget'])
                ->minus(Money::of((string) $prior['budget']))->toDecimal(),
            'actual' => Money::of((string) $current['actual'])
                ->minus(Money::of((string) $prior['actual']))->toDecimal(),
            'variance' => Money::of((string) $current['variance'])
                ->minus(Money::of((string) $prior['variance']))->toDecimal(),
        ];
    }

    /**
     * The variance as a percentage of the plan, or null when there is no plan
     * to be a percentage of.
     *
     * The variance is scaled up before it is divided rather than after, because
     * Money::dividedBy() quantises to the money scale: dividing first would
     * round the ratio to four decimal places and only then make it a
     * percentage, so a variance of a sixth of the plan would read as 16.6700%
     * instead of 16.6667%.
     */
    private function percent(Money $actual, Money $budget): ?string
    {
        if ($budget->isZero()) {
            return null;
        }

        return $actual->minus($budget)
            ->times('100')
            ->dividedBy($budget->toDecimal())
            ->toDecimal();
    }

    /**
     * The fiscal period immediately before this one, read from the company's
     * own calendar rather than by stepping back a fixed number of months.
     *
     * Which period that is comes from PeriodComparison, the one place that
     * answers what "the previous period" means, so the comparative column here
     * and the one on the profit and loss report cannot disagree about it.
     */
    private function priorPeriod(int $companyId, FiscalPeriod $period): ?FiscalPeriod
    {
        $endsOn = $this->periods->previousPeriodEnd($companyId, $period->starts_on->toDateString());

        if ($endsOn === null) {
            return null;
        }

        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('ends_on', $endsOn)
            ->first();
    }
}
