<?php

namespace App\Domain\Reporting\Services;

use App\Models\Company;
use App\Support\Money;
use Throwable;

/**
 * The Financial Manager forecast page, composed in one read.
 *
 * One endpoint answers for the whole page, and every block on it is a separate
 * entry under `sections`. Each entry is produced inside its own try/catch, so a
 * block that cannot be read answers with its own error and the rest of the page
 * is still returned — the same arrangement DashboardQuery uses, for the same
 * reason: one failing read must not take the screen down with it.
 *
 * Nothing here computes accounting. The projection itself is ForecastQuery's
 * answer, read from the plan and the posted ledger through the same services
 * the reports use; the ageing is AgeingQuery's and the cash position is
 * CashFlowQuery's. What this class adds is the arithmetic that only the page
 * needs — a variance against the plan, a margin, a cumulative break-even point,
 * a scenario band — and all of it is done with bcmath on decimal strings, never
 * with a float.
 *
 * The scenario is passed in, never stored. A scenario is a set of figures
 * handed to a read, so the projection is re-stated under those figures in
 * memory and no row is written anywhere. The envelope therefore records the
 * assumptions the answer was produced under, and every section that could be
 * mistaken for a fact says so itself.
 */
final class ForecastPageQuery
{
    /**
     * Intermediate precision for the ratio work here — margins, growth factors
     * and rates. Above the money scale so a single operation cannot be rounded
     * twice, matching the convention in App\Support\Money.
     */
    private const WORKING_SCALE = 12;

    public function __construct(
        private readonly ForecastQuery $forecast,
        private readonly AgeingQuery $ageing,
        private readonly CashFlowQuery $cash,
        private readonly CashRunway $runway,
    ) {}

    /**
     * @param  array{name: string, revenue_growth: string, expense_growth: string, adjustments: array<int, string>}  $scenario
     * @return array<string, mixed>
     */
    public function page(int $companyId, ?string $asOf, array $scenario, bool $mayRunScenarios): array
    {
        // The one read that can refuse outright. A company with no calendar, a
        // date no period covers, or a fiscal year that is not open has no
        // projection to build anything else from, so it is answered as a whole
        // rather than buried in a section: the page shows an empty state for
        // it, and there is nothing else on the page to show.
        $base = $this->forecast->forecast($companyId, $asOf);
        $projection = $this->adjust($base, $scenario);

        $sections = [
            'projection' => $this->section(static fn (): array => $projection),
            'kpis' => $this->section(fn (): array => $this->kpis($companyId, $projection)),
            'chart' => $this->section(fn (): array => $this->chart($base, $projection)),
            'cash_outlook' => $this->section(fn (): array => $this->cashOutlook($companyId, $projection)),
            'cash_runway' => $this->section(fn (): array => $this->cashRunway($companyId, $projection)),
            'working_capital' => $this->section(fn (): array => $this->workingCapital($companyId, $projection)),
            'break_even' => $this->section(fn (): array => $this->breakEven($projection)),
            'budget_vs_forecast' => $this->section(fn (): array => $this->budgetVsForecast($projection)),
            'risk_alerts' => $this->section(fn (): array => $this->riskAlerts($companyId, $projection)),
        ];

        // The comparison is the Financial Manager's own right. A reader who may
        // read the projection but not test it is given no section at all rather
        // than an empty one, so the page can leave the panel off entirely.
        if ($mayRunScenarios) {
            $sections['scenario_comparison'] = $this->section(fn (): array => $this->comparison($base, $scenario));
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'company' => $this->company($companyId),
            'window' => $this->window($projection),
            'history' => $this->history($base),
            'scenario' => $this->scenario($scenario),
            'capabilities' => ['scenario' => $mayRunScenarios],
            'sections' => $sections,
        ];
    }

    /**
     * Run one section and let it fail alone.
     *
     * @return array{data: array<string, mixed>|null, error: array<string, string>|null}
     */
    private function section(callable $produce): array
    {
        try {
            return ['data' => $produce(), 'error' => null];
        } catch (Throwable $exception) {
            return [
                'data' => null,
                'error' => [
                    'message' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'This part of the forecast could not be read.',
                    'type' => $exception::class,
                ],
            ];
        }
    }

    /**
     * The projection restated under the scenario's assumptions.
     *
     * A base scenario moves nothing, so the payload is handed back exactly as
     * ForecastQuery produced it and no figure is touched. Otherwise the growth
     * is applied to each projected account and the account adjustments are
     * added on top of it, and the period and horizon totals are re-added from
     * the rows that resulted — so a total can never disagree with the rows
     * printed beneath it.
     *
     * The plan is deliberately left alone. `budget` is what the company wrote
     * and a scenario does not rewrite it; it is the thing the projection is
     * compared against, so scaling it would compare a figure with itself.
     *
     * @param  array<string, mixed>  $projection
     * @param  array{name: string, revenue_growth: string, expense_growth: string, adjustments: array<int, string>}  $scenario
     * @return array<string, mixed>
     */
    private function adjust(array $projection, array $scenario): array
    {
        if (Money::of($scenario['revenue_growth'])->isZero()
            && Money::of($scenario['expense_growth'])->isZero()
            && $scenario['adjustments'] === []) {
            return $projection;
        }

        $revenueFactor = $this->factor($scenario['revenue_growth']);
        $expenseFactor = $this->factor($scenario['expense_growth']);

        $projection['periods'] = array_map(
            fn (array $period): array => $this->applyToPeriod(
                $period,
                $revenueFactor,
                $expenseFactor,
                $scenario['adjustments'],
            ),
            $projection['periods'],
        );

        $projection['horizon_totals'] = $this->horizonTotals(
            $projection['periods'],
            $projection['horizon_totals']['budget'],
        );

        $projection['assumptions'] = [...$projection['assumptions'], ...$this->scenarioAssumptions($scenario)];

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $period
     * @param  array<int, string>  $adjustments
     * @return array<string, mixed>
     */
    private function applyToPeriod(array $period, string $revenueFactor, string $expenseFactor, array $adjustments): array
    {
        $period['revenue'] = $this->applyToSection($period['revenue'], $revenueFactor, $adjustments);
        $period['expense'] = $this->applyToSection($period['expense'], $expenseFactor, $adjustments);

        $period['net'] = [
            'actual' => Money::of($period['revenue']['actual'])
                ->minus(Money::of($period['expense']['actual']))->toDecimal(),
            'forecast' => $period['revenue']['forecast'] === null
                ? null
                : Money::of($period['revenue']['forecast'])
                    ->minus(Money::of($period['expense']['forecast']))->toDecimal(),
        ];

        return $period;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<int, string>  $adjustments
     * @return array<string, mixed>
     */
    private function applyToSection(array $section, string $factor, array $adjustments): array
    {
        $rows = [];

        foreach ($section['accounts'] as $row) {
            // A period already past carries no projection to grow, and an
            // adjustment to an account the period does not list is an
            // adjustment to something the projection has nothing to say about.
            if ($row['forecast'] !== null) {
                $amount = Money::of((string) $row['forecast'])->times($factor);

                if (array_key_exists((int) $row['account_id'], $adjustments)) {
                    $amount = $amount->plus(Money::of($adjustments[(int) $row['account_id']]));
                }

                $row['forecast'] = $amount->toDecimal();
            }

            $rows[] = $row;
        }

        $section['accounts'] = $rows;
        $section['forecast'] = $section['forecast'] === null ? null : $this->sum($rows, 'forecast');

        return $section;
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @param  array{revenue: string, expense: string, net: string}  $budget
     * @return array<string, mixed>
     */
    private function horizonTotals(array $periods, array $budget): array
    {
        $revenue = [];
        $expense = [];

        foreach ($periods as $period) {
            if (! $period['is_forecast']) {
                continue;
            }

            $revenue[] = $period['revenue'];
            $expense[] = $period['expense'];
        }

        return [
            'revenue' => [
                'actual' => $this->sum($revenue, 'actual'),
                'forecast' => $this->sum($revenue, 'forecast'),
            ],
            'expense' => [
                'actual' => $this->sum($expense, 'actual'),
                'forecast' => $this->sum($expense, 'forecast'),
            ],
            'net' => [
                'actual' => Money::of($this->sum($revenue, 'actual'))
                    ->minus(Money::of($this->sum($expense, 'actual')))->toDecimal(),
                'forecast' => Money::of($this->sum($revenue, 'forecast'))
                    ->minus(Money::of($this->sum($expense, 'forecast')))->toDecimal(),
            ],
            'budget' => $budget,
        ];
    }

    /**
     * The six cards.
     *
     * Each carries its own comparison with the plan, because a projection with
     * nothing to hold it against is a number without a question. Where the plan
     * cannot answer — the company wrote no plan for any projected period, so
     * there is no plan margin or plan break-even to compare with — the budget
     * side is null and the card says nothing rather than a zero that would read
     * as a plan.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function kpis(int $companyId, array $projection): array
    {
        $totals = $projection['horizon_totals'];

        $revenue = Money::of($totals['revenue']['forecast']);
        $expense = Money::of($totals['expense']['forecast']);
        $net = Money::of($totals['net']['forecast']);

        $budgetRevenue = Money::of($totals['budget']['revenue']);
        $budgetExpense = Money::of($totals['budget']['expense']);
        $budgetNet = Money::of($totals['budget']['net']);

        // Asked of the periods rather than of the amounts: a plan of nothing is
        // still a plan, and a company that planned nothing has nothing to
        // compare a projection with.
        $hasPlan = false;

        foreach ($projection['periods'] as $period) {
            if ($period['is_forecast'] && $period['has_plan']) {
                $hasPlan = true;

                break;
            }
        }

        $cash = $this->cashProjection($companyId, $projection);
        $breakEven = $this->breakEvenMonth($projection, false);
        $budgetBreakEven = $this->breakEvenMonth($projection, true);

        return [
            'cards' => [
                $this->card('revenue', 'Projected Revenue', 'money', $revenue->toDecimal(),
                    $hasPlan ? $budgetRevenue->toDecimal() : null,
                    'What the rest of the year is expected to earn, from the plan where the company wrote one and the trailing run-rate where it did not.'),
                $this->card('expense', 'Projected Expenses', 'money', $expense->toDecimal(),
                    $hasPlan ? $budgetExpense->toDecimal() : null,
                    'What the rest of the year is expected to cost, on the same basis as the revenue above.'),
                $this->card('net_profit', 'Projected Net Profit', 'money', $net->toDecimal(),
                    $hasPlan ? $budgetNet->toDecimal() : null,
                    'Projected revenue less projected expenses. It is a projection and not a posted result.'),
                $this->card('margin', 'Projected Profit Margin', 'percent',
                    $this->margin($net, $revenue),
                    $hasPlan ? $this->margin($budgetNet, $budgetRevenue) : null,
                    'Projected net profit as a share of projected revenue.'),
                $this->card('year_end_cash', 'Projected Year-End Cash', 'money',
                    $cash['projected_closing_cash'],
                    null,
                    'An estimate: today\'s cash, plus the projected net profit, plus what is expected to be collected, less what is expected to be paid. No cash budget is held, so there is nothing to compare it with.'),
                $this->card('break_even', 'Break-even Month', 'month',
                    $breakEven,
                    $hasPlan ? $budgetBreakEven : null,
                    'The first projected month in which the year to date turns profitable, counting from the current period.'),
            ],
            'note' => 'Every figure here is a projection. The budget column is the plan the company wrote, where it wrote one, and is shown for comparison only.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(
        string $key,
        string $label,
        string $format,
        ?string $forecast,
        ?string $budget,
        string $note,
    ): array {
        $variance = null;
        $percent = null;
        $direction = 'unknown';

        // A variance is only meaningful between two amounts. The break-even card
        // carries month names rather than figures, so it is shown beside the
        // plan's month without a difference being taken between the two.
        if ($format !== 'month' && $forecast !== null && $budget !== null) {
            $difference = Money::of($forecast)->minus(Money::of($budget));
            $variance = $difference->toDecimal();
            $percent = $this->percent($difference, Money::of($budget));
            $direction = $this->direction($difference);
        }

        return [
            'key' => $key,
            'label' => $label,
            'format' => $format,
            'forecast' => $forecast,
            'budget' => $budget,
            'variance' => $variance,
            'variance_percent' => $percent,
            'direction' => $direction,
            'note' => $note,
        ];
    }

    /**
     * The chart's data.
     *
     * The lines are the projection the page is showing; the shaded band is the
     * company's own pessimistic-to-optimistic envelope, read from the base
     * projection so it describes the year rather than the scenario currently
     * selected. Actual and projected are separate keys rather than one series
     * with a gap, so a renderer cannot join them into a single line and present
     * the future as if it had happened.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function chart(array $base, array $projection): array
    {
        $series = [];

        foreach ([['key' => 'revenue', 'label' => 'Revenue'], ['key' => 'net', 'label' => 'Net Profit']] as $metric) {
            $points = [];

            foreach ($projection['periods'] as $index => $period) {
                $forecast = $period[$metric['key']]['forecast'] ?? null;
                $source = $base['periods'][$index] ?? null;

                $points[] = [
                    'period' => $period['period']['name'],
                    'period_no' => $period['period']['period_no'],
                    'is_forecast' => $period['is_forecast'],
                    'actual' => $period[$metric['key']]['actual'],
                    'forecast' => $forecast,
                    'low' => $source === null ? null : $this->band($source, $metric['key'], 'pessimistic'),
                    'high' => $source === null ? null : $this->band($source, $metric['key'], 'optimistic'),
                ];
            }

            $series[] = ['key' => $metric['key'], 'label' => $metric['label'], 'points' => $points];
        }

        return [
            'periods' => array_map(
                static fn (array $period): array => [
                    'name' => $period['period']['name'],
                    'is_forecast' => $period['is_forecast'],
                ],
                $projection['periods'],
            ),
            'series' => $series,
            'note' => 'Solid lines are what the ledger posted; dashed lines are projections. The shaded band is the pessimistic-to-optimistic range and is not a promise.',
        ];
    }

    /**
     * What a period's figure would be under one named scenario, read from the
     * base projection and the scenario table rather than recomputed row by row.
     *
     * @param  array<string, mixed>  $period
     */
    private function band(array $period, string $metric, string $name): ?string
    {
        if (! $period['is_forecast']) {
            return null;
        }

        $rates = config("nexas.forecast.scenarios.{$name}");

        if (! is_array($rates)) {
            return $period[$metric]['forecast'];
        }

        // Net profit is the difference of two lines that grow at their own
        // rates, so it is restated from both rather than scaled itself.
        if ($metric === 'net') {
            $revenue = $this->scaleSection($period['revenue'], $this->factor((string) $rates['revenue_growth']));
            $expense = $this->scaleSection($period['expense'], $this->factor((string) $rates['expense_growth']));

            return Money::of($this->sum($revenue, 'forecast'))
                ->minus(Money::of($this->sum($expense, 'forecast')))->toDecimal();
        }

        return $this->sum($this->scaleSection($period[$metric], $this->factor((string) $rates['revenue_growth'])), 'forecast');
    }

    /**
     * The same rows with the projection scaled and no other change, used to
     * restate a period under a scenario without touching the payload.
     *
     * @param  array<string, mixed>  $section
     * @return list<array<string, mixed>>
     */
    private function scaleSection(array $section, string $factor): array
    {
        $rows = [];

        foreach ($section['accounts'] as $row) {
            if ($row['forecast'] !== null) {
                $row['forecast'] = Money::of((string) $row['forecast'])->times($factor)->toDecimal();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Cash, projected from the ageing.
     *
     * An estimate and labelled as one: what is owed is aged, a share of what is
     * due is assumed to be collected and a share of what is owed assumed to be
     * paid, both at rates held in configuration rather than invented here, and
     * the projected net profit is added. No cash budget is held, so this is the
     * only way the question can be answered at all — and the answer says so.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function cashProjection(int $companyId, array $projection): array
    {
        $asOf = (string) $projection['as_of'];

        $position = $this->cash->position($companyId, $asOf);
        $receivables = $this->ageing->receivables($companyId, $asOf);
        $payables = $this->ageing->payables($companyId, $asOf);

        $opening = Money::of((string) $position['total']);
        $receivable = Money::of((string) $receivables['totals']['total']);
        $payable = Money::of((string) $payables['totals']['total']);
        $profit = Money::of((string) $projection['horizon_totals']['net']['forecast']);

        $collected = $receivable->times((string) config('nexas.forecast.collection_rate', '0.9'));
        $paid = $payable->times((string) config('nexas.forecast.payment_rate', '0.95'));
        $movement = $profit->plus($collected)->minus($paid);

        return [
            'as_of' => $asOf,
            'is_estimate' => true,
            'opening_cash' => $opening->toDecimal(),
            'projected_net_profit' => $profit->toDecimal(),
            'receivables_outstanding' => $receivable->toDecimal(),
            'payables_outstanding' => $payable->toDecimal(),
            'collection_rate' => (string) config('nexas.forecast.collection_rate', '0.9'),
            'payment_rate' => (string) config('nexas.forecast.payment_rate', '0.95'),
            'expected_collections' => $collected->toDecimal(),
            'expected_payments' => $paid->toDecimal(),
            'projected_movement' => $movement->toDecimal(),
            'projected_closing_cash' => $opening->plus($movement)->toDecimal(),
            'receivable_buckets' => $receivables['totals'],
            'payable_buckets' => $payables['totals'],
            'document_count' => [
                'receivables' => $receivables['document_count'],
                'payables' => $payables['document_count'],
            ],
            'note' => 'An estimate, not a projection the ledger supports: the collection and payment rates above are assumptions held in '
                .'configuration, and no cash budget exists to compare the answer with. The receivables and payables figures themselves '
                .'are read from the same ageing report the dashboard shows.',
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function cashOutlook(int $companyId, array $projection): array
    {
        return $this->cashProjection($companyId, $projection);
    }

    /**
     * How long the cash lasts, under the same projection the page is showing.
     *
     * The threshold is a company setting rather than a constant: what counts as
     * low cash is the company's to say, and the panel reports which it used —
     * the company's own figure or the deployment default — so a reader is never
     * left guessing why an alert did or did not appear.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function cashRunway(int $companyId, array $projection): array
    {
        $threshold = $this->threshold($companyId);

        return $this->runway->analyse($companyId, $projection, $threshold['value']) + [
            'threshold_source' => $threshold['source'],
            'history_months' => (int) ($projection['run_rate']['period_count'] ?? 0),
        ];
    }

    /**
     * The low-cash threshold in force, and where it came from.
     *
     * @return array{value: string, source: string}
     */
    private function threshold(int $companyId): array
    {
        $settings = Company::query()->whereKey($companyId)->value('settings');
        $configured = is_array($settings) ? ($settings['forecast_low_cash_threshold'] ?? null) : null;

        if ($configured !== null && $configured !== '') {
            return ['value' => Money::of((string) $configured)->toDecimal(), 'source' => 'company'];
        }

        return [
            'value' => Money::of((string) config('nexas.forecast.low_cash_threshold', '0'))->toDecimal(),
            'source' => 'default',
        ];
    }

    /**
     * Receivables, payables and cash at the end of the horizon.
     *
     * The closing balances follow from the same collection and payment
     * assumptions the cash outlook uses, so the two panels cannot disagree: what
     * is assumed collected is what leaves receivables, and what is assumed paid
     * is what leaves payables.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function workingCapital(int $companyId, array $projection): array
    {
        $cash = $this->cashProjection($companyId, $projection);

        $receivable = Money::of($cash['receivables_outstanding']);
        $payable = Money::of($cash['payables_outstanding']);
        $revenue = Money::of((string) $projection['horizon_totals']['revenue']['forecast']);
        $expense = Money::of((string) $projection['horizon_totals']['expense']['forecast']);
        $collected = Money::of($cash['expected_collections']);
        $paid = Money::of($cash['expected_payments']);

        $projectedReceivable = $receivable->minus($collected)->plus($revenue);
        $projectedPayable = $payable->minus($paid)->plus($expense);

        return [
            'as_of' => $cash['as_of'],
            'is_estimate' => true,
            'receivables' => $receivable->toDecimal(),
            'payables' => $payable->toDecimal(),
            'projected_receivables' => $projectedReceivable->toDecimal(),
            'projected_payables' => $projectedPayable->toDecimal(),
            'working_capital' => $receivable->minus($payable)->toDecimal(),
            'projected_working_capital' => $projectedReceivable->minus($projectedPayable)->toDecimal(),
            'projected_cash' => $cash['projected_closing_cash'],
            'note' => 'The closing balances assume the year\'s projected revenue is billed and not yet collected, and the year\'s projected '
                .'expenses are billed and not yet paid, less the shares assumed settled. They are estimates and carry the same assumptions '
                .'as the cash outlook.',
        ];
    }

    /**
     * When the year to date turns profitable.
     *
     * Read cumulatively from the current period, because a break-even point is
     * a running total and not a single month: a month can be profitable and the
     * year still be behind. A period the company wrote no plan for contributes
     * nothing to the budget side, which is the truth about the plan rather than
     * a gap left to be read as zero profit.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function breakEven(array $projection): array
    {
        $forecast = [];
        $budget = [];
        $below = [];
        $cumulative = Money::zero();
        $budgetCumulative = Money::zero();
        $month = null;
        $budgetMonth = null;

        foreach ($projection['periods'] as $period) {
            if (! $period['is_forecast']) {
                continue;
            }

            $name = (string) $period['period']['name'];
            $net = Money::of((string) $period['net']['forecast']);
            $planned = Money::of((string) ($period['budget']['net'] ?? '0'));

            $cumulative = $cumulative->plus($net);
            $budgetCumulative = $budgetCumulative->plus($planned);

            if ($cumulative->isNegative()) {
                $below[] = $name;
            }

            if ($month === null && $cumulative->isPositive()) {
                $month = $name;
            }

            if ($budgetMonth === null && $budgetCumulative->isPositive()) {
                $budgetMonth = $name;
            }

            $forecast[] = ['period' => $name, 'net' => $net->toDecimal(), 'cumulative' => $cumulative->toDecimal()];
            $budget[] = ['period' => $name, 'net' => $planned->toDecimal(), 'cumulative' => $budgetCumulative->toDecimal()];
        }

        return [
            'month' => $month,
            'budget_month' => $budgetMonth,
            'profitable' => $month !== null,
            'below_break_even' => $below,
            'trend' => $forecast,
            'budget_trend' => $budget,
            'note' => 'The running total starts at the current period, so a month already behind the company is not counted here. '
                .'A month is below break-even while the year to date is still negative.',
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function breakEvenMonth(array $projection, bool $budget): ?string
    {
        $cumulative = Money::zero();

        foreach ($projection['periods'] as $period) {
            if (! $period['is_forecast']) {
                continue;
            }

            $cumulative = $cumulative->plus(Money::of((string) ($budget
                ? ($period['budget']['net'] ?? '0')
                : $period['net']['forecast'])));

            if ($cumulative->isPositive()) {
                return (string) $period['period']['name'];
            }
        }

        return null;
    }

    /**
     * The projection against the plan, period by period, with the basis each
     * figure came from said out loud.
     *
     * A negative variance is not automatically bad and a positive one is not
     * automatically good — spending less than planned is favourable and earning
     * less is not — so the direction is decided per line rather than by sign.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function budgetVsForecast(array $projection): array
    {
        $rows = [];

        foreach ($projection['periods'] as $period) {
            if (! $period['is_forecast']) {
                continue;
            }

            $revenue = Money::of((string) $period['revenue']['forecast']);
            $expense = Money::of((string) $period['expense']['forecast']);
            $net = Money::of((string) $period['net']['forecast']);

            $planned = $period['budget']['net'] === null ? null : Money::of((string) $period['budget']['net']);
            $variance = $planned === null ? null : $net->minus($planned);

            $rows[] = [
                'period' => $period['period']['name'],
                'period_no' => $period['period']['period_no'],
                'revenue' => $revenue->toDecimal(),
                'expense' => $expense->toDecimal(),
                'net' => $net->toDecimal(),
                'budget' => $planned?->toDecimal(),
                'variance' => $variance?->toDecimal(),
                'variance_percent' => $variance === null ? null : $this->percent($variance, $planned),
                'direction' => $variance === null ? 'unknown' : $this->direction($variance),
                'source' => $this->source($period),
                'source_reason' => $this->sourceReason($period),
            ];
        }

        return [
            'rows' => $rows,
            'note' => 'The budget column is the plan as the company wrote it. A period the company wrote no plan for has no budget figure to '
                .'compare with and reads as null rather than as zero.',
        ];
    }

    /**
     * Which basis a period's figures were built from, in one word.
     *
     * @param  array<string, mixed>  $period
     */
    private function source(array $period): string
    {
        $sources = [];

        foreach (['revenue', 'expense'] as $section) {
            foreach ($period[$section]['accounts'] as $row) {
                $sources[$row['source'] ?? 'none'] = true;
            }
        }

        $keys = array_keys($sources);

        return count($keys) === 1 ? (string) $keys[0] : 'mixed';
    }

    /**
     * @param  array<string, mixed>  $period
     */
    private function sourceReason(array $period): string
    {
        return match ($this->source($period)) {
            'budget' => 'The company wrote a plan for this period, so the plan is the projection.',
            'run_rate' => 'The company wrote no plan for this period, so it is projected at the trailing run-rate.',
            'none' => 'Nothing was planned and nothing has been posted, so the period projects nothing.',
            default => 'Some accounts were planned and others were not, so the plan is used where it exists and the run-rate beside it.',
        };
    }

    /**
     * What could go wrong with the year, said plainly.
     *
     * These are readings, not controls: nothing on this page can block a
     * payment or move a figure. Each alert names the figure it came from so a
     * reader can go and check it rather than take it on trust.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function riskAlerts(int $companyId, array $projection): array
    {
        $alerts = [];

        $cash = $this->cashProjection($companyId, $projection);
        $closing = Money::of($cash['projected_closing_cash']);

        if ($closing->isNegative()) {
            $alerts[] = [
                'level' => 'critical',
                'code' => 'negative_cash',
                'title' => 'Projected cash runs out',
                'detail' => 'Cash is projected to close the year at '.$closing->format().' on the assumptions in the cash outlook. '
                    .'That is an estimate, and it is the estimate the assumptions produce.',
                'amount' => $closing->toDecimal(),
            ];
        }

        $breakEven = $this->breakEven($projection);

        foreach ($breakEven['below_break_even'] as $month) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'below_break_even',
                'title' => 'Below break-even in '.$month,
                'detail' => 'The year to date is still negative at the end of '.$month.'',
                'amount' => null,
            ];
        }

        $warning = Money::of((string) config('nexas.forecast.variance_warning_percent', '10'));

        foreach ($projection['periods'] as $period) {
            if (! $period['is_forecast'] || $period['budget']['net'] === null) {
                continue;
            }

            $variance = Money::of((string) $period['net']['forecast'])
                ->minus(Money::of((string) $period['budget']['net']));
            $percent = $this->percent($variance->absolute(), Money::of((string) $period['budget']['net'])->absolute());

            if ($percent !== null && Money::of($percent)->greaterThan($warning)) {
                $alerts[] = [
                    'level' => 'warning',
                    'code' => 'budget_variance',
                    'title' => 'Budget variance in '.$period['period']['name'],
                    'detail' => 'The projection differs from the plan by '.$percent.'%, above the '
                        .$warning->toDecimal().'% this build flags.',
                    'amount' => $variance->toDecimal(),
                ];
            }
        }

        $receivable = Money::of($cash['receivables_outstanding']);
        $payable = Money::of($cash['payables_outstanding']);

        if ($payable->greaterThan($receivable->plus(Money::of($cash['opening_cash'])))) {
            $alerts[] = [
                'level' => 'warning',
                'code' => 'working_capital_pressure',
                'title' => 'Working capital pressure',
                'detail' => 'What is owed to suppliers is more than what customers owe plus the cash on hand, so the timing of '
                    .'collections matters to what can be paid.',
                'amount' => $payable->minus($receivable)->minus(Money::of($cash['opening_cash']))->toDecimal(),
            ];
        }

        return [
            'alerts' => $alerts,
            'count' => count($alerts),
            'note' => 'Alerts are readings of the projection and not controls. Nothing here blocks a payment or moves a figure.',
        ];
    }

    /**
     * The three named scenarios, side by side.
     *
     * Built from the base projection in memory, so the comparison costs no
     * further database reads and cannot drift from the figures the page is
     * showing. The scenario the request named has its rates replaced by the
     * request's own, so the column the reader is looking at is the column they
     * asked for, and the other two stay at the company's configured envelope.
     *
     * @param  array<string, mixed>  $base
     * @param  array{name: string, revenue_growth: string, expense_growth: string, adjustments: array<int, string>}  $scenario
     * @return array<string, mixed>
     */
    private function comparison(array $base, array $scenario): array
    {
        $configured = config('nexas.forecast.scenarios');
        $configured = is_array($configured) ? $configured : [];

        $rows = [];

        foreach ($configured as $name => $rates) {
            $rates = is_array($rates) ? $rates : [];
            $selected = $name === $scenario['name'];

            $revenueGrowth = $selected ? $scenario['revenue_growth'] : (string) ($rates['revenue_growth'] ?? '0');
            $expenseGrowth = $selected ? $scenario['expense_growth'] : (string) ($rates['expense_growth'] ?? '0');

            $stated = $this->adjust($base, [
                'name' => (string) $name,
                'revenue_growth' => $revenueGrowth,
                'expense_growth' => $expenseGrowth,
                'adjustments' => $scenario['adjustments'],
            ]);

            $totals = $stated['horizon_totals'];
            $net = Money::of((string) $totals['net']['forecast']);

            $rows[] = [
                'name' => (string) $name,
                'label' => ucfirst((string) $name),
                'selected' => $selected,
                'revenue_growth' => $revenueGrowth,
                'expense_growth' => $expenseGrowth,
                'revenue' => $totals['revenue']['forecast'],
                'expense' => $totals['expense']['forecast'],
                'net' => $net->toDecimal(),
                'margin' => $this->margin($net, Money::of((string) $totals['revenue']['forecast'])),
                'budget' => $totals['budget']['net'],
                'variance' => $net->minus(Money::of((string) $totals['budget']['net']))->toDecimal(),
                'direction' => $this->direction($net->minus(Money::of((string) $totals['budget']['net']))),
            ];
        }

        return [
            'scenarios' => $rows,
            'adjustments' => $scenario['adjustments'],
            'note' => 'A scenario is a set of assumptions handed to this read and nothing else: no scenario is stored, none is named after '
                .'the year, and none of these figures can reach the ledger or an actual report.',
        ];
    }

    /**
     * @return list<string>
     */
    private function scenarioAssumptions(array $scenario): array
    {
        $lines = [];

        if (! Money::of($scenario['revenue_growth'])->isZero()) {
            $lines[] = 'Revenue in every projected period is moved by '.$scenario['revenue_growth'].'% on the scenario the reader asked for. '
                .'The plan itself is not rewritten: it is what the projection is compared against.';
        }

        if (! Money::of($scenario['expense_growth'])->isZero()) {
            $lines[] = 'Expenses in every projected period are moved by '.$scenario['expense_growth'].'% on the same basis.';
        }

        if ($scenario['adjustments'] !== []) {
            $lines[] = count($scenario['adjustments']).' named account(s) carry an adjustment on top of the growth, applied only to '
                .'periods the account is projected in.';
        }

        return $lines;
    }

    /**
     * A growth rate as a multiplier. Kept as a decimal string at working
     * precision and applied in a single multiplication, so the rate is not
     * rounded before it is used.
     */
    private function factor(string $growthPercent): string
    {
        return bcdiv(bcadd('100', $growthPercent, self::WORKING_SCALE), '100', self::WORKING_SCALE);
    }

    /**
     * One money figure as a percentage of another, or null when the whole is
     * zero and the question has no answer.
     */
    private function percent(Money $part, Money $whole): ?string
    {
        if ($whole->isZero()) {
            return null;
        }

        return Money::of(bcdiv(
            bcmul($part->toDecimal(), '100', self::WORKING_SCALE),
            $whole->toDecimal(),
            self::WORKING_SCALE,
        ))->toDecimal();
    }

    private function margin(Money $net, Money $revenue): ?string
    {
        return $this->percent($net, $revenue);
    }

    private function direction(Money $variance): string
    {
        if ($variance->isZero()) {
            return 'neutral';
        }

        return $variance->isNegative() ? 'unfavourable' : 'favourable';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $key): string
    {
        return Money::sum(array_map(
            static fn (array $row): Money => Money::of((string) $row[$key]),
            $rows,
        ))->toDecimal();
    }

    /**
     * @return array<string, mixed>
     */
    private function company(int $companyId): array
    {
        $company = Company::query()->whereKey($companyId)->first();

        return [
            'id' => $companyId,
            'name' => $company?->name,
            'currency' => $company?->base_currency_code,
        ];
    }

    /**
     * How much history the projection actually rests on.
     *
     * The count is read from the projection's own run-rate record — the number
     * of completed periods of this fiscal year the trailing average was taken
     * over — and never assumed. It is the honest answer to "how much is this
     * forecast built on": a projection in the first period of a year rests on
     * nothing at all, and the page has to be able to say so rather than imply a
     * depth of evidence that is not there.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function history(array $base): array
    {
        $runRate = $base['run_rate'];
        $months = (int) $runRate['period_count'];

        return [
            'months' => $months,
            'label' => sprintf('Based on %d month%s of historical data', $months, $months === 1 ? '' : 's'),
            'available' => (bool) $runRate['used'],
            'from' => $runRate['from'],
            'to' => $runRate['to'],
            'periods' => $runRate['periods'],
            'basis' => 'Average per period of what each account actually posted, read from the posted ledger through the same profit '
                .'and loss statement the reports use.',
            'limitations' => $this->historyLimitations($months),
        ];
    }

    /**
     * What the run-rate cannot answer, said plainly rather than left implied.
     *
     * @return list<string>
     */
    private function historyLimitations(int $months): array
    {
        $limitations = [
            'The run-rate covers only the completed periods of the current fiscal year. Earlier years are not read, so a company '
                .'early in its year is projected from a short history.',
            'Where the company wrote a plan for a period, the plan is the projection and the run-rate is not used for it. A period '
                .'the company planned is therefore only as reliable as the plan.',
        ];

        if ($months === 0) {
            $limitations[] = 'No completed period exists yet in this fiscal year, so there is no history to average. Where the company '
                .'wrote no plan, nothing is projected for that period at all.';
        } elseif ($months < 3) {
            $limitations[] = sprintf(
                'Only %d completed period%s of history %s available, so the run-rate rests on a short sample and a single unusual '
                .'month can move it sharply.',
                $months,
                $months === 1 ? '' : 's',
                $months === 1 ? 'is' : 'are',
            );
        }

        return $limitations;
    }

    /**
     * The assumptions the page was produced under, named as they will be read.
     *
     * A scenario loaded from a saved record carries its identity and version so
     * the page can say which saved scenario and which of its versions is on
     * screen. It is recalculated against current actuals every time it is
     * opened — nothing about the saved assumptions is cached — so an old
     * version read today is restated on today's ledger, and the payload says so
     * rather than letting a reader believe they are looking at last month's
     * answer.
     *
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    private function scenario(array $scenario): array
    {
        $saved = (bool) ($scenario['saved'] ?? false);

        return [
            'name' => (string) $scenario['name'],
            'type' => (string) ($scenario['type'] ?? $scenario['name']),
            'revenue_growth' => (string) $scenario['revenue_growth'],
            'expense_growth' => (string) $scenario['expense_growth'],
            'adjustments' => $scenario['adjustments'],
            'saved' => $saved,
            'scenario_ulid' => $scenario['scenario_ulid'] ?? null,
            'version_no' => isset($scenario['version_no']) ? (int) $scenario['version_no'] : null,
            'updated_at' => $scenario['updated_at'] ?? null,
            'recalculated_with_current_actuals' => $saved,
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function window(array $projection): array
    {
        return [
            'as_of' => $projection['as_of'],
            'fiscal_year' => $projection['fiscal_year']['name'],
            'from_period' => $projection['horizon']['from_period_name'],
            'to_period' => $projection['horizon']['to_period_name'],
            'period_count' => $projection['horizon']['period_count'],
            'ends_on' => $projection['horizon']['ends_on'],
        ];
    }
}
