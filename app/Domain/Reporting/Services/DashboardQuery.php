<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Enums\FiscalStatus;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Inventory\Enums\ProductType;
use App\Domain\Inventory\Enums\StockItemStatus;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Models\ApprovalRequest;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Supplier;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The whole Financial Manager dashboard in one read.
 *
 * A dashboard is a composition rather than a report: every figure on it is
 * another report's answer, and the job here is to ask for all of them, once,
 * for one company and one window, and hand back something a screen can draw
 * without making a dozen calls of its own.
 *
 * Two rules shape the file.
 *
 * The first is that nothing is computed twice. Revenue, expense, net profit,
 * cash, the ageing, the valuation, the plan and the projection all come from
 * the same query services the read-only report endpoints use, so a number on
 * the dashboard cannot disagree with the report a reader clicks through to.
 * Where a series is needed that no service offers — a figure per fiscal period
 * rather than for the window as a whole — the service that owns the rule is
 * still asked, once per period, rather than the rule being written again here.
 *
 * The second is that no section can take the dashboard down with it. Each is
 * produced inside its own guard, so a section that fails answers with its own
 * error and the rest of the dashboard still renders. A reader whose company has
 * no budget sees every other card and one honest message, not a blank page
 * because one of nine reads refused.
 *
 * Everything is scoped to the company it is asked for. There is no parameter
 * that names a company, so there is no parameter that can name a different one.
 */
final class DashboardQuery
{
    /**
     * How many fiscal periods a series will show. A year of monthly periods is
     * twelve; a company on a shorter cycle would otherwise be handed a chart
     * with fifty points on it, which is a line no one can read.
     */
    private const SERIES_LIMIT = 12;

    public function __construct(
        private readonly LedgerQuery $ledger,
        private readonly FinancialStatementQuery $statements,
        private readonly CashFlowQuery $cashFlow,
        private readonly AgeingQuery $ageing,
        private readonly StockValuationQuery $valuation,
        private readonly BudgetVarianceQuery $budgets,
        private readonly ForecastQuery $forecast,
        private readonly PeriodComparison $periods,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $companyId, ?string $from, ?string $to): array
    {
        $window = $this->window($companyId, $from, $to);
        $calendar = $this->calendar($companyId, $window);

        // The per-period series is the expensive part and three sections read
        // it, so it is built once. If it cannot be built, the sections drawn
        // from it carry the same error and everything else still renders.
        $seriesSection = $this->section(fn (): array => $this->series($companyId, $calendar));
        $series = is_array($seriesSection['data']) ? $seriesSection['data'] : [];
        $seriesError = $seriesSection['error'];

        $company = Company::query()->whereKey($companyId)->first();

        return [
            'generated_at' => now()->toIso8601String(),
            'company' => [
                'id' => $companyId,
                'name' => $company instanceof Company ? (string) $company->name : '',
                // The company's own currency, under the name the rest of the
                // API reports it by. The column is base_currency_code; a
                // missing company is reported as no currency rather than
                // guessed at.
                'currency_code' => $company instanceof Company ? (string) $company->base_currency_code : '',
            ],
            'window' => $window,
            'sections' => [
                'kpis' => $this->section(fn (): array => $this->kpis($companyId, $window, $series)),
                'trend' => $this->seriesSection($series, $seriesError, [
                    'measures' => [
                        ['key' => 'revenue', 'label' => 'Revenue'],
                        ['key' => 'expense', 'label' => 'Expenses'],
                        ['key' => 'net_profit', 'label' => 'Net Profit'],
                    ],
                ]),
                'cash_flow' => $this->seriesSection($series, $seriesError, [
                    'measures' => [
                        ['key' => 'cash_in', 'label' => 'Inflows'],
                        ['key' => 'cash_out', 'label' => 'Outflows'],
                        ['key' => 'cash_net', 'label' => 'Net movement'],
                    ],
                ]),
                'budget' => $this->seriesSection($series, $seriesError, [
                    'measures' => [
                        ['key' => 'budget_revenue', 'label' => 'Budgeted revenue'],
                        ['key' => 'actual_revenue', 'label' => 'Actual revenue'],
                        ['key' => 'budget_expense', 'label' => 'Budgeted expense'],
                        ['key' => 'actual_expense', 'label' => 'Actual expense'],
                    ],
                    'variance_basis' => 'actual_minus_budget',
                    'note' => 'The budget column is the plan the company wrote. It is a plan and not '
                        .'a transaction: writing one posts no journal, so it appears in no trial '
                        .'balance and the ledger does not know it exists.',
                ]),
                'ageing' => $this->section(fn (): array => $this->ageing($companyId, $window)),
                'approvals' => $this->section(fn (): array => $this->approvals($companyId)),
                'alerts' => $this->section(fn (): array => $this->alerts($companyId, $window)),
                'forecast' => $this->section(fn (): array => $this->forecast->forecast($companyId, $window['to'])),
                'reconciliation' => $this->section(fn (): array => $this->reconciliation($companyId, $window, $series)),
            ],
        ];
    }

    /* ------------------------------------------------------------ sections */

    /**
     * Run one section and let it fail alone.
     *
     * @param  callable(): array<string, mixed>  $produce
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
                        : 'This part of the dashboard could not be read.',
                    'type' => $exception::class,
                ],
            ];
        }
    }

    /**
     * A section whose body is the shared series plus whatever else it states.
     *
     * @param  array<string, mixed>  $series
     * @param  array<string, string>|null  $error
     * @param  array<string, mixed>  $extra
     * @return array{data: array<string, mixed>|null, error: array<string, string>|null}
     */
    private function seriesSection(array $series, ?array $error, array $extra): array
    {
        if ($error !== null) {
            return ['data' => null, 'error' => $error];
        }

        return ['data' => ['series' => $series] + $extra, 'error' => null];
    }

    /* -------------------------------------------------------------- window */

    /**
     * The window the dashboard is read over, and what it is compared against.
     *
     * A caller may name a range — that is what the Month, Quarter, Year and
     * Custom filters send — or name nothing, in which case the company's own
     * calendar answers with the period today falls in. A half-named range is
     * completed from that same period rather than being read as the year zero,
     * so a filter that sends only a start still asks a question with an answer.
     *
     * @return array<string, mixed>
     */
    private function window(int $companyId, ?string $from, ?string $to): array
    {
        $asOf = now()->toDateString();
        $current = $this->currentPeriod($companyId, $asOf);

        $basis = match (true) {
            $from !== null && $to !== null => 'custom',
            $current !== null => 'fiscal_period',
            default => 'all_dates',
        };

        $resolvedFrom = $from ?? $current?->starts_on?->toDateString();
        $resolvedTo = $to ?? $current?->ends_on?->toDateString();

        $prior = $resolvedFrom !== null && $resolvedTo !== null
            ? $this->periods->precedingWindow($resolvedFrom, $resolvedTo)
            : null;

        return [
            'from' => $resolvedFrom,
            'to' => $resolvedTo,
            'basis' => $basis,
            'as_of' => $resolvedTo ?? $asOf,
            'period' => $current === null ? null : [
                'ulid' => $current->ulid,
                'name' => $current->name,
                'period_no' => (int) $current->period_no,
                'starts_on' => $current->starts_on->toDateString(),
                'ends_on' => $current->ends_on->toDateString(),
                'status' => $current->status->value,
            ],
            'period_status' => $current?->status->value ?? 'unknown',
            'prior' => $prior === null ? null : ['from' => $prior[0], 'to' => $prior[1]],
            'comparison_basis' => 'equal_length_window_immediately_before',
        ];
    }

    private function currentPeriod(int $companyId, string $asOf): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('starts_on', '<=', $asOf)
            ->where('ends_on', '>=', $asOf)
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * The fiscal periods the series are drawn over: those overlapping the
     * window, oldest first, trimmed to the most recent few when a company's
     * cycle would put more points on a chart than anyone can read.
     *
     * @param  array<string, mixed>  $window
     * @return array{periods: list<FiscalPeriod>, truncated: bool}
     */
    private function calendar(int $companyId, array $window): array
    {
        $from = $window['from'];
        $to = $window['to'];

        if (! is_string($from) || ! is_string($to)) {
            return ['periods' => [], 'truncated' => false];
        }

        $periods = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->orderBy('starts_on')
            ->get()
            ->all();

        $truncated = count($periods) > self::SERIES_LIMIT;

        if ($truncated) {
            $periods = array_slice($periods, -self::SERIES_LIMIT);
        }

        return ['periods' => array_values($periods), 'truncated' => $truncated];
    }

    /* --------------------------------------------------------------- kpis */

    /**
     * The eight executive measures, each with what it was a period earlier.
     *
     * The flow measures come from the profit and loss statement with its own
     * comparative column switched on, so the prior figures and the variances are
     * the report's arithmetic rather than a second copy of it. The standing
     * measures — what is in the bank, what is owed, what is on the shelf — are
     * read a second time as at the day before the window, because a balance has
     * no window of its own to halve.
     *
     * @param  array<string, mixed>  $window
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    private function kpis(int $companyId, array $window, array $series): array
    {
        $from = $window['from'];
        $to = $window['to'];
        $priorTo = $window['prior']['to'] ?? null;

        $statement = $this->statements->profitAndLoss($companyId, $from, $to, true);

        $cash = $this->cashFlow->position($companyId, $to);
        $receivables = $this->ageing->receivables($companyId, $to);
        $payables = $this->ageing->payables($companyId, $to);
        $valuation = $this->valuation->valuation($companyId, $to);

        $priorCash = $priorTo === null ? null : $this->cashFlow->position($companyId, $priorTo);
        $priorReceivables = $priorTo === null ? null : $this->ageing->receivables($companyId, $priorTo);
        $priorPayables = $priorTo === null ? null : $this->ageing->payables($companyId, $priorTo);
        $priorValuation = $priorTo === null ? null : $this->valuation->valuation($companyId, $priorTo);

        $revenue = $this->money($statement['revenue']['total']);
        $expense = $this->money($statement['expense']['total']);
        $net = $this->money($statement['net_profit']);

        $priorStatement = is_array($statement['comparison'] ?? null) ? $statement['comparison'] : null;
        $priorRevenue = $priorStatement === null ? null : $this->money($priorStatement['revenue']['total']);
        $priorExpense = $priorStatement === null ? null : $this->money($priorStatement['expense']['total']);
        $priorNet = $priorStatement === null ? null : $this->money($priorStatement['net_profit']);

        $profitAndLoss = [
            'key' => 'profit_and_loss',
            'route' => '/financial-manager/reports?report=profit_and_loss',
            'api' => '/api/v1/reports/profit-and-loss',
            'label' => 'Profit and Loss',
        ];

        return [
            'window' => [
                'from' => $from,
                'to' => $to,
                'prior_from' => $window['prior']['from'] ?? null,
                'prior_to' => $priorTo,
            ],
            'cards' => [
                $this->card('revenue', 'Revenue', $revenue, $priorRevenue, true, $profitAndLoss, $series['revenue'] ?? []),
                $this->card('expenses', 'Expenses', $expense, $priorExpense, false, $profitAndLoss, $series['expense'] ?? []),
                $this->card('net_profit', 'Net Profit', $net, $priorNet, true, $profitAndLoss, $series['net_profit'] ?? []),
                $this->marginCard($revenue, $net, $priorRevenue, $priorNet, $series['net_profit'] ?? []),
                $this->card('cash_and_bank', 'Cash and Bank', $this->money($cash['total']), $priorCash === null ? null : $this->money($priorCash['total']), true, [
                    'key' => 'cash_position',
                    'route' => '/financial-manager/reports?report=cash_position',
                    'api' => '/api/v1/reports/cash-position',
                    'label' => 'Cash position',
                ], $series['cash_balance'] ?? []),
                $this->card('accounts_receivable', 'Accounts Receivable', $this->total($receivables), $priorReceivables === null ? null : $this->total($priorReceivables), null, [
                    'key' => 'receivables_ageing',
                    'route' => '/financial-manager/reports?report=receivables_ageing',
                    'api' => '/api/v1/reports/receivables-ageing',
                    'label' => 'Receivables ageing',
                ], $series['receivable_balance'] ?? []),
                $this->card('accounts_payable', 'Accounts Payable', $this->total($payables), $priorPayables === null ? null : $this->total($priorPayables), null, [
                    'key' => 'payables_ageing',
                    'route' => '/financial-manager/reports?report=payables_ageing',
                    'api' => '/api/v1/reports/payables-ageing',
                    'label' => 'Payables ageing',
                ], $series['payable_balance'] ?? []),
                $this->card('inventory_valuation', 'Inventory Valuation', $this->money($valuation['total_value']), $priorValuation === null ? null : $this->money($priorValuation['total_value']), null, [
                    'key' => 'stock_valuation',
                    'route' => '/financial-manager/reports?report=stock_valuation',
                    'api' => '/api/v1/reports/stock-valuation',
                    'label' => 'Stock valuation',
                ], $series['inventory_value'] ?? []),
            ],
        ];
    }

    /**
     * @param  array<string, string>  $report
     * @param  list<string>  $sparkline
     * @return array<string, mixed>
     */
    private function card(
        string $key,
        string $label,
        Money $value,
        ?Money $prior,
        ?bool $higherIsBetter,
        array $report,
        array $sparkline,
    ): array {
        $variance = $prior === null ? null : $value->minus($prior);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value->toDecimal(),
            'prior' => $prior?->toDecimal(),
            'variance' => $variance?->toDecimal(),
            'variance_percent' => $prior === null ? null : $this->percent($variance, $prior),
            'direction' => $variance === null ? 'unknown' : match (true) {
                $variance->isZero() => 'flat',
                $variance->isPositive() => 'up',
                default => 'down',
            },
            'higher_is_better' => $higherIsBetter,
            'report' => $report,
            'sparkline' => $sparkline,
        ];
    }

    /**
     * Profit margin is a ratio rather than a balance, so it does not fit the
     * card above and is built by hand: the same net profit over the same
     * revenue, stated as a percentage.
     *
     * @param  list<string>  $sparkline
     * @return array<string, mixed>
     */
    private function marginCard(
        Money $revenue,
        Money $net,
        ?Money $priorRevenue,
        ?Money $priorNet,
        array $sparkline,
    ): array {
        $value = $this->ratio($net, $revenue);
        $prior = $priorRevenue === null || $priorNet === null ? null : $this->ratio($priorNet, $priorRevenue);
        $variance = $value === null || $prior === null ? null : round($value - $prior, 2);

        return [
            'key' => 'profit_margin',
            'label' => 'Profit Margin',
            'value' => $value === null ? null : number_format($value, 2, '.', ''),
            'prior' => $prior === null ? null : number_format($prior, 2, '.', ''),
            'variance' => $variance === null ? null : number_format($variance, 2, '.', ''),
            'variance_percent' => $variance,
            'unit' => 'percent',
            'direction' => $variance === null ? 'unknown' : match (true) {
                abs($variance) < 0.005 => 'flat',
                $variance > 0 => 'up',
                default => 'down',
            },
            'higher_is_better' => true,
            'report' => [
                'key' => 'profit_and_loss',
                'route' => '/financial-manager/reports?report=profit_and_loss',
                'api' => '/api/v1/reports/profit-and-loss',
                'label' => 'Profit and Loss',
            ],
            'sparkline' => $sparkline,
        ];
    }

    /* -------------------------------------------------------------- series */

    /**
     * The per-period series every chart and sparkline is drawn from: one point
     * per fiscal period in the window, in the order they occurred.
     *
     * The flow figures are the profit and loss statement read once per period
     * and the cash movement read once for the whole run, grouped by the period
     * its journal belongs to. The standing figures are the control accounts as
     * at each period's own end, read one period at a time so a balance is a
     * balance and not an accumulation done here.
     *
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    private function series(int $companyId, array $calendar): array
    {
        /** @var list<FiscalPeriod> $periods */
        $periods = $calendar['periods'];

        if ($periods === []) {
            return [
                'periods' => [],
                'truncated' => (bool) $calendar['truncated'],
                'opening_cash' => null,
                'props' => [],
            ] + $this->emptyMeasures();
        }

        $labels = [];
        $revenue = [];
        $expense = [];
        $net = [];
        $budgetRevenue = [];
        $actualRevenue = [];
        $budgetExpense = [];
        $actualExpense = [];

        foreach ($periods as $period) {
            $from = $period->starts_on->toDateString();
            $to = $period->ends_on->toDateString();

            // The statement owns what counts as revenue and expense; the plan
            // owns what was budgeted. Neither rule is restated here.
            $statement = $this->statements->profitAndLoss($companyId, $from, $to);
            $plan = $this->budgets->report($companyId, $period);

            $labels[] = [
                'ulid' => $period->ulid,
                'name' => $period->name,
                'period_no' => (int) $period->period_no,
                'starts_on' => $from,
                'ends_on' => $to,
                'status' => $period->status->value,
            ];

            $revenue[] = $this->money($statement['revenue']['total'])->toDecimal();
            $expense[] = $this->money($statement['expense']['total'])->toDecimal();
            $net[] = $this->money($statement['net_profit'])->toDecimal();

            $budgetRevenue[] = $this->money($plan['revenue']['budget'])->toDecimal();
            $actualRevenue[] = $this->money($plan['revenue']['actual'])->toDecimal();
            $budgetExpense[] = $this->money($plan['expense']['budget'])->toDecimal();
            $actualExpense[] = $this->money($plan['expense']['actual'])->toDecimal();
        }

        $runFrom = $periods[0]->starts_on->toDateString();
        $runTo = $periods[count($periods) - 1]->ends_on->toDateString();

        $cash = $this->cashByPeriod($companyId, $runFrom, $runTo);

        $moneyIds = $this->moneyAccountIds($companyId);
        $receivableIds = $this->accountIdsFrom(Customer::query(), 'receivable_account_id', $companyId);
        $payableIds = $this->accountIdsFrom(Supplier::query(), 'payable_account_id', $companyId);
        $inventoryIds = $this->accountIdsFrom(Product::withTrashed(), 'inventory_account_id', $companyId);

        $allIds = array_values(array_unique(array_merge($moneyIds, $receivableIds, $payableIds, $inventoryIds)));

        $cashIn = [];
        $cashOut = [];
        $cashNet = [];
        $cashBalances = [];
        $receivableBalances = [];
        $payableBalances = [];
        $inventoryValues = [];

        // The cash line starts from what was in the bank the day before the
        // first period in view and walks forward a period at a time — an
        // accumulation, but of the ledger's own movements rather than of a
        // balance report's totals.
        $opening = $this->sumAccounts(
            $companyId,
            $moneyIds,
            CarbonImmutable::parse($runFrom)->subDay()->toDateString()
        );
        $running = $opening;

        foreach ($periods as $period) {
            $in = $this->money($cash[(int) $period->getKey()]['inflow'] ?? 0);
            $out = $this->money($cash[(int) $period->getKey()]['outflow'] ?? 0);

            $cashIn[] = $in->toDecimal();
            $cashOut[] = $out->toDecimal();
            $cashNet[] = $in->minus($out)->toDecimal();

            $running = $running->plus($in)->minus($out);
            $cashBalances[] = $running->toDecimal();

            $asOf = $period->ends_on->toDateString();
            $balances = $allIds === [] ? [] : $this->ledger->accountBalances($companyId, $allIds, $asOf);

            $receivableBalances[] = $this->sumOf($balances, $receivableIds)->toDecimal();
            $payableBalances[] = $this->sumOf($balances, $payableIds)->negated()->toDecimal();
            $inventoryValues[] = $this->money($this->valuation->valuation($companyId, $asOf)['total_value'])->toDecimal();
        }

        return [
            'periods' => $labels,
            'truncated' => (bool) $calendar['truncated'],
            'opening_cash' => $opening->toDecimal(),
            'revenue' => $revenue,
            'expense' => $expense,
            'net_profit' => $net,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'cash_net' => $cashNet,
            'cash_balance' => $cashBalances,
            'budget_revenue' => $budgetRevenue,
            'actual_revenue' => $actualRevenue,
            'budget_expense' => $budgetExpense,
            'actual_expense' => $actualExpense,
            'receivable_balance' => $receivableBalances,
            'payable_balance' => $payableBalances,
            'inventory_value' => $inventoryValues,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function emptyMeasures(): array
    {
        return [
            'revenue' => [],
            'expense' => [],
            'net_profit' => [],
            'cash_in' => [],
            'cash_out' => [],
            'cash_net' => [],
            'cash_balance' => [],
            'budget_revenue' => [],
            'actual_revenue' => [],
            'budget_expense' => [],
            'actual_expense' => [],
            'receivable_balance' => [],
            'payable_balance' => [],
            'inventory_value' => [],
        ];
    }

    /* -------------------------------------------------------------- ageing */

    /**
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function ageing(int $companyId, array $window): array
    {
        $asOf = $window['as_of'];

        return [
            'as_of' => $asOf,
            'buckets' => AgeingQuery::BUCKETS,
            'receivables' => $this->ageing->receivables($companyId, $asOf),
            'payables' => $this->ageing->payables($companyId, $asOf),
            'links' => [
                'receivables' => [
                    'route' => '/financial-manager/reports?report=receivables_ageing',
                    'api' => '/api/v1/reports/receivables-ageing',
                ],
                'payables' => [
                    'route' => '/financial-manager/reports?report=payables_ageing',
                    'api' => '/api/v1/reports/payables-ageing',
                ],
            ],
        ];
    }

    /* ----------------------------------------------------------- approvals */

    /**
     * @return array<string, mixed>
     */
    private function approvals(int $companyId): array
    {
        $query = fn () => ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('status', ApprovalStatus::Pending->value);

        $total = $query()->count();

        $items = $query()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (ApprovalRequest $approval): array => [
                'ulid' => $approval->ulid,
                'document_type' => $approval->document_type->value,
                'document_type_label' => $approval->document_type->label(),
                'document_ulid' => $approval->document_ulid,
                'document_reference' => $approval->document_reference,
                'status' => $approval->status->value,
                'created_at' => $approval->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'total' => $total,
            'items' => $items,
            'route' => '/financial-manager/approvals',
            'api' => '/api/v1/approval-requests?status=pending&per_page=5',
        ];
    }

    /* -------------------------------------------------------------- alerts */

    /**
     * The five things a Financial Manager is expected to act on, each with a
     * severity rather than a colour chosen at the screen.
     *
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function alerts(int $companyId, array $window): array
    {
        $asOf = $window['as_of'];

        return [
            'as_of' => $asOf,
            'items' => [
                $this->overdueInvoices($companyId, $asOf),
                $this->billsDueSoon($companyId, $asOf),
                $this->lowStock($companyId),
                $this->unreconciledBankLines($companyId),
                $this->openPeriods($companyId, $asOf),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueInvoices(int $companyId, string $asOf): array
    {
        $open = fn () => Invoice::query()
            ->where('company_id', $companyId)
            ->where('document_type', DocumentType::Invoice->value)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_date', '<', $asOf)
            ->whereRaw('(total - amount_paid) > 0');

        $rows = $open()->orderBy('due_date')->limit(5)->get(['ulid', 'invoice_no', 'due_date', 'total', 'amount_paid']);
        $totals = $open()->selectRaw('COUNT(*) AS documents, COALESCE(SUM(total - amount_paid), 0) AS outstanding')->first();
        $count = (int) ($totals->documents ?? 0);

        return [
            'key' => 'overdue_invoices',
            'label' => 'Overdue customer invoices',
            'severity' => $count > 0 ? 'critical' : 'ok',
            'count' => $count,
            'total' => $this->money($totals->outstanding ?? 0)->toDecimal(),
            'detail' => $count > 0
                ? $count.' invoice(s) are past their due date and still carry a balance.'
                : 'No customer invoice is past its due date with a balance on it.',
            'items' => $rows->map(fn (Invoice $invoice): array => [
                'ulid' => $invoice->ulid,
                'reference' => $invoice->invoice_no,
                'due_date' => $invoice->due_date->toDateString(),
                'outstanding' => $this->money($invoice->total)->minus($this->money($invoice->amount_paid))->toDecimal(),
            ])->all(),
            'route' => '/financial-manager/reports?report=receivables_ageing',
            'api' => '/api/v1/reports/receivables-ageing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billsDueSoon(int $companyId, string $asOf): array
    {
        $until = CarbonImmutable::parse($asOf)->addDays(7)->toDateString();

        $open = fn () => Bill::query()
            ->where('company_id', $companyId)
            ->where('document_type', DocumentType::Bill->value)
            ->whereIn('status', [BillStatus::Approved->value, BillStatus::PartiallyPaid->value])
            ->whereBetween('due_date', [$asOf, $until])
            ->whereRaw('(total - amount_paid) > 0');

        $rows = $open()->orderBy('due_date')->limit(5)->get(['ulid', 'bill_no', 'due_date', 'total', 'amount_paid']);
        $totals = $open()->selectRaw('COUNT(*) AS documents, COALESCE(SUM(total - amount_paid), 0) AS outstanding')->first();
        $count = (int) ($totals->documents ?? 0);

        return [
            'key' => 'bills_due_soon',
            'label' => 'Supplier bills due soon',
            'severity' => $count > 0 ? 'warning' : 'ok',
            'count' => $count,
            'total' => $this->money($totals->outstanding ?? 0)->toDecimal(),
            'detail' => $count > 0
                ? $count.' bill(s) fall due within the next seven days.'
                : 'No supplier bill falls due in the next seven days.',
            'items' => $rows->map(fn (Bill $bill): array => [
                'ulid' => $bill->ulid,
                'reference' => $bill->bill_no,
                'due_date' => $bill->due_date->toDateString(),
                'outstanding' => $this->money($bill->total)->minus($this->money($bill->amount_paid))->toDecimal(),
            ])->all(),
            'route' => '/financial-manager/reports?report=payables_ageing',
            'api' => '/api/v1/reports/payables-ageing',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lowStock(int $companyId): array
    {
        $onHand = DB::table('stock_layers')
            ->where('company_id', $companyId)
            ->selectRaw('product_id, SUM(quantity_remaining) AS on_hand')
            ->groupBy('product_id')
            ->pluck('on_hand', 'product_id')
            ->all();

        $watched = Product::query()
            ->where('company_id', $companyId)
            ->where('product_type', ProductType::Goods->value)
            ->where('status', StockItemStatus::Active->value)
            ->whereNotNull('reorder_level')
            ->orderBy('sku')
            ->get(['id', 'ulid', 'sku', 'name', 'reorder_level']);

        $low = [];

        foreach ($watched as $product) {
            $have = Quantity::of((string) ($onHand[(int) $product->getKey()] ?? '0'));
            $level = Quantity::of((string) $product->reorder_level);

            if (Quantity::compare($have, $level) > 0) {
                continue;
            }

            $low[] = [
                'ulid' => $product->ulid,
                'sku' => $product->sku,
                'name' => $product->name,
                'on_hand' => $have,
                'reorder_level' => $level,
            ];
        }

        return [
            'key' => 'low_stock',
            'label' => 'Low-stock items',
            'severity' => $low === [] ? 'ok' : 'warning',
            'count' => count($low),
            'total' => null,
            'detail' => $low === []
                ? 'Every watched item is above its reorder level.'
                : count($low).' item(s) are at or below their reorder level.',
            'items' => array_slice($low, 0, 5),
            'route' => '/financial-manager/reports?report=stock_valuation',
            'api' => '/api/v1/reports/stock-valuation',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unreconciledBankLines(int $companyId): array
    {
        $count = BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('status', BankStatementLineStatus::Unmatched->value)
            ->count();

        return [
            'key' => 'unreconciled_bank_lines',
            'label' => 'Unreconciled bank statement lines',
            'severity' => $count > 0 ? 'warning' : 'ok',
            'count' => $count,
            'total' => null,
            'detail' => $count > 0
                ? $count.' imported statement line(s) have not been tied to the books.'
                : 'Every imported statement line has been matched or set aside.',
            'items' => [],
            'route' => '/financial-manager/periods',
            'api' => '/api/v1/bank-accounts',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function openPeriods(int $companyId, string $asOf): array
    {
        $rows = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', FiscalStatus::Open->value)
            ->where('ends_on', '<', $asOf)
            ->orderBy('ends_on')
            ->get(['ulid', 'name', 'starts_on', 'ends_on']);

        return [
            'key' => 'open_periods',
            'label' => 'Open periods past their end',
            'severity' => $rows->isEmpty() ? 'ok' : 'info',
            'count' => $rows->count(),
            'total' => null,
            'detail' => $rows->isEmpty()
                ? 'No open period has ended without being closed.'
                : $rows->count().' open period(s) have ended and are waiting to be closed.',
            'items' => $rows->map(fn (FiscalPeriod $period): array => [
                'ulid' => $period->ulid,
                'name' => $period->name,
                'ends_on' => $period->ends_on->toDateString(),
            ])->all(),
            'route' => '/financial-manager/periods',
            'api' => '/api/v1/fiscal-periods',
        ];
    }

    /* ------------------------------------------------------- reconciliation */

    /**
     * The tie-outs that prove the dashboard is stating the ledger rather than a
     * second opinion about it.
     *
     * Each check reads two figures reached by different routes — the ageing
     * against the control accounts, the twelve monthly movements against the
     * one movement the cash flow statement states for the year — and reports
     * the difference. Nothing is adjusted to make a difference vanish: a
     * difference is the finding, and the block states it rather than hiding it.
     *
     * @param  array<string, mixed>  $window
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    private function reconciliation(int $companyId, array $window, array $series): array
    {
        $from = is_string($window['from'] ?? null) ? $window['from'] : null;
        $to = (string) $window['as_of'];

        $checks = [
            $this->trialBalanceCheck($companyId, $from, $to),
            $this->incomeStatementCheck($companyId, $from, $to),
            $this->receivablesCheck($companyId, $to),
            $this->payablesCheck($companyId, $to),
            $this->cashCheck($companyId, $to),
            $this->inventoryCheck($companyId, $to),
        ];

        $skipped = [];

        if (($series['periods'] ?? []) === []) {
            $skipped['cash_flow_series'] = 'No fiscal periods cover this window, so there is no series to tie out.';
        } else {
            $checks[] = $this->cashFlowSeriesCheck($companyId, $from, $to, $series);
        }

        $reconciled = true;

        foreach ($checks as $check) {
            if (($check['reconciled'] ?? false) !== true) {
                $reconciled = false;
            }
        }

        return [
            'reconciled' => $reconciled,
            'checks' => $checks,
            'skipped' => $skipped,
            'note' => 'Each check compares two figures that were reached by different routes. A '
                .'difference is reported as it stands and is never adjusted away: a dashboard that '
                .'agrees with the ledger because it was made to agree proves nothing.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trialBalanceCheck(int $companyId, ?string $from, string $to): array
    {
        $trial = $this->ledger->trialBalance($companyId, $from, $to, 1, PHP_INT_MAX);

        $debit = $this->money($trial['meta']['total_debit']);
        $credit = $this->money($trial['meta']['total_credit']);

        return [
            'key' => 'trial_balance',
            'label' => 'Trial balance debits against credits',
            'expected_label' => 'Total credit',
            'expected' => $credit->toDecimal(),
            'actual_label' => 'Total debit',
            'actual' => $debit->toDecimal(),
            'difference' => $debit->minus($credit)->toDecimal(),
            'reconciled' => $debit->equals($credit),
            'basis' => 'Posted and reversed journals over the selected window.',
        ];
    }

    /**
     * The dashboard's own profit figure against the same figure reached from the
     * trial balance's revenue and expense accounts, which is the accounting
     * statement that what is on the screen is the ledger's.
     *
     * @return array<string, mixed>
     */
    private function incomeStatementCheck(int $companyId, ?string $from, string $to): array
    {
        $trial = $this->ledger->trialBalance($companyId, $from, $to, 1, PHP_INT_MAX);
        $statement = $this->statements->profitAndLoss($companyId, $from, $to);

        $revenue = Money::zero();
        $expense = Money::zero();

        foreach ($trial['data'] as $row) {
            $net = $this->money($row['net']);

            if ($row['account_type'] === AccountType::Revenue->value) {
                $revenue = $revenue->minus($net);
            } elseif ($row['account_type'] === AccountType::Expense->value) {
                $expense = $expense->plus($net);
            }
        }

        $fromTrial = $revenue->minus($expense);
        $fromStatement = $this->money($statement['net_profit']);

        return [
            'key' => 'income_statement',
            'label' => 'Net profit on the dashboard against the trial balance',
            'expected_label' => 'Trial balance revenue less expense',
            'expected' => $fromTrial->toDecimal(),
            'actual_label' => 'Dashboard net profit',
            'actual' => $fromStatement->toDecimal(),
            'difference' => $fromStatement->minus($fromTrial)->toDecimal(),
            'reconciled' => $fromStatement->equals($fromTrial),
            'basis' => 'Revenue accounts are credit-normal, so their net is negated; expense '
                .'accounts are debit-normal and are taken as they stand.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receivablesCheck(int $companyId, string $asOf): array
    {
        $controlIds = $this->accountIdsFrom(Customer::query(), 'receivable_account_id', $companyId);
        $control = $this->sumAccounts($companyId, $controlIds, $asOf);
        $ageing = $this->money($this->ageing->receivables($companyId, $asOf)['totals']['total'] ?? 0);

        return [
            'key' => 'receivables_ageing',
            'label' => 'Receivables ageing against the receivable control accounts',
            'expected_label' => 'Control account balance',
            'expected' => $control->toDecimal(),
            'actual_label' => 'Ageing total',
            'actual' => $ageing->toDecimal(),
            'difference' => $ageing->minus($control)->toDecimal(),
            'control_accounts' => count($controlIds),
            'reconciled' => $ageing->equals($control),
            'basis' => 'The control accounts are the receivable account each customer names, read '
                .'as at '.$asOf.'.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payablesCheck(int $companyId, string $asOf): array
    {
        $controlIds = $this->accountIdsFrom(Supplier::query(), 'payable_account_id', $companyId);
        $control = $this->sumAccounts($companyId, $controlIds, $asOf)->negated();
        $ageing = $this->money($this->ageing->payables($companyId, $asOf)['totals']['total'] ?? 0);

        return [
            'key' => 'payables_ageing',
            'label' => 'Payables ageing against the payable control accounts',
            'expected_label' => 'Control account balance',
            'expected' => $control->toDecimal(),
            'actual_label' => 'Ageing total',
            'actual' => $ageing->toDecimal(),
            'difference' => $ageing->minus($control)->toDecimal(),
            'control_accounts' => count($controlIds),
            'reconciled' => $ageing->equals($control),
            'basis' => 'A payable is credit-normal, so the ledger balance is negated to state what '
                .'the company owes as a positive figure as at '.$asOf.'.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashCheck(int $companyId, string $asOf): array
    {
        $moneyIds = $this->moneyAccountIds($companyId);
        $ledger = $this->sumAccounts($companyId, $moneyIds, $asOf);
        $position = $this->money($this->cashFlow->position($companyId, $asOf)['total']);

        return [
            'key' => 'cash_position',
            'label' => 'Cash position against the cash and bank ledger accounts',
            'expected_label' => 'Ledger balances of the cash and bank accounts',
            'expected' => $ledger->toDecimal(),
            'actual_label' => 'Cash position total',
            'actual' => $position->toDecimal(),
            'difference' => $position->minus($ledger)->toDecimal(),
            'reconciled' => $position->equals($ledger),
            'basis' => 'The same accounts the cash position report lists, read straight from the '
                .'ledger as at '.$asOf.'.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryCheck(int $companyId, string $asOf): array
    {
        $valuation = $this->valuation->valuation($companyId, $asOf);

        $difference = Money::zero();

        foreach ($valuation['control'] as $line) {
            $difference = $difference->plus($this->money($line['difference']));
        }

        $total = $this->money($valuation['total_value']);

        return [
            'key' => 'inventory_valuation',
            'label' => 'Stock valuation against the inventory accounts in the ledger',
            'expected_label' => 'Ledger balance of the inventory accounts',
            'expected' => $total->minus($difference)->toDecimal(),
            'actual_label' => 'Stock on hand',
            'actual' => $total->toDecimal(),
            'difference' => $difference->toDecimal(),
            'reconciled' => $valuation['reconciled'] === true,
            'basis' => 'The layers are the one place the quantity is recorded; the valuation report '
                .'compares them with the inventory accounts as at '.$asOf.'.',
        ];
    }

    /**
     * The chart's twelve monthly movements against the one movement the cash
     * flow statement states for the whole run. The two are read by different
     * routes — a grouped sum over the ledger, and the report that classifies
     * every journal that touched a money account — so agreement is not a
     * foregone conclusion.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    private function cashFlowSeriesCheck(int $companyId, ?string $from, string $to, array $series): array
    {
        $statement = $this->cashFlow->statement($companyId, $from, $to);

        $seriesNet = Money::zero();

        foreach ($series['cash_net'] ?? [] as $point) {
            $seriesNet = $seriesNet->plus($this->money($point));
        }

        $statementNet = $this->money($statement['net_movement']);

        return [
            'key' => 'cash_flow_series',
            'label' => 'Period cash movements against the cash flow statement',
            'expected_label' => 'Cash flow statement net movement',
            'expected' => $statementNet->toDecimal(),
            'actual_label' => 'Sum of the period movements',
            'actual' => $seriesNet->toDecimal(),
            'difference' => $seriesNet->minus($statementNet)->toDecimal(),
            'reconciled' => $seriesNet->equals($statementNet),
            'basis' => 'The series groups the same money accounts by the fiscal period their journal '
                .'belongs to; the statement classifies the same journals by category.',
        ];
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Cash in and cash out per fiscal period, in one pass over the ledger.
     *
     * This is the one figure on the dashboard with no per-period service behind
     * it: the cash flow statement answers for a window, and a chart needs an
     * answer for each period in it. It is read as the movement on the same
     * accounts the cash flow report classifies as money — a debit is money in
     * and a credit is money out — so the monthly movements add up to the one
     * movement the statement states, which the reconciliation block checks.
     *
     * @return array<int, array{inflow: string, outflow: string}>
     */
    private function cashByPeriod(int $companyId, string $from, string $to): array
    {
        $rows = DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.company_id', $companyId)
            ->whereIn('journals.status', JournalStatus::ledgerStatuses())
            ->whereBetween('journal_lines.posting_date', [$from, $to])
            ->where(fn ($query) => $query->where('accounts.is_cash', true)->orWhere('accounts.is_bank', true))
            ->groupBy('journals.fiscal_period_id')
            ->selectRaw('journals.fiscal_period_id AS period_id, COALESCE(SUM(journal_lines.debit), 0) AS inflow, COALESCE(SUM(journal_lines.credit), 0) AS outflow')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->period_id] = [
                'inflow' => (string) $row->inflow,
                'outflow' => (string) $row->outflow,
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function moneyAccountIds(int $companyId): array
    {
        $ids = [];

        foreach ($this->cashFlow->moneyAccounts($companyId) as $account) {
            $ids[] = (int) $account->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * The distinct account ids a party or product table names in a given
     * column, scoped to the company.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return list<int>
     */
    private function accountIdsFrom($query, string $column, int $companyId): array
    {
        $ids = $query
            ->where('company_id', $companyId)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->all();

        $out = [];

        foreach ($ids as $id) {
            $out[] = (int) $id;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<int>  $accountIds
     */
    private function sumAccounts(int $companyId, array $accountIds, ?string $asOf): Money
    {
        if ($accountIds === []) {
            return Money::zero();
        }

        return $this->sumOf($this->ledger->accountBalances($companyId, $accountIds, $asOf), $accountIds);
    }

    /**
     * @param  array<int, Money>  $balances
     * @param  list<int>  $accountIds
     */
    private function sumOf(array $balances, array $accountIds): Money
    {
        $total = Money::zero();

        foreach ($accountIds as $id) {
            if (isset($balances[$id])) {
                $total = $total->plus($balances[$id]);
            }
        }

        return $total;
    }

    /**
     * The total an ageing report states, whichever way it was reached.
     *
     * @param  array<string, mixed>  $report
     */
    private function total(array $report): Money
    {
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];

        return $this->money($totals['total'] ?? 0);
    }

    /**
     * Net profit over revenue as a percentage, or null when there is no revenue
     * to divide by — a margin against nothing is not a figure.
     */
    private function ratio(Money $numerator, Money $denominator): ?float
    {
        if ($denominator->isZero()) {
            return null;
        }

        return round(((float) $numerator->toDecimal() / (float) $denominator->toDecimal()) * 100, 2);
    }

    private function percent(?Money $variance, Money $prior): ?float
    {
        if ($variance === null || $prior->isZero()) {
            return null;
        }

        return round(((float) $variance->toDecimal() / (float) $prior->toDecimal()) * 100, 2);
    }

    private function money(mixed $value): Money
    {
        if ($value instanceof Money) {
            return $value;
        }

        if ($value === null || $value === '') {
            return Money::zero();
        }

        return Money::of($value);
    }
}
