<?php

namespace App\Domain\Reporting\Exports;

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Exceptions\UnknownExportTypeException;
use App\Domain\Reporting\Services\DashboardQuery;
use App\Domain\Reporting\Services\FinancialStatementQuery;
use App\Domain\Reporting\Services\ForecastPageQuery;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Models\Company;
use App\Support\Money;

/**
 * The reports the export pipeline can render, in one place.
 *
 * Each entry is a ReportDefinition: the type a request names, the right that
 * guards it, the window it accepts, and the closure that turns the report's
 * own answer into a ReportDocument the two writers can print. Nothing here
 * computes an accounting figure — every number comes from the same query
 * service the read-only API endpoint uses, so an exported statement and the
 * statement on screen are the same statement read once more, and cannot
 * disagree.
 *
 * The scope is the financial statements first: profit and loss, balance sheet
 * and trial balance, each with an optional comparative column where the report
 * supports one. Other families — ledgers and ageing, then cash and treasury,
 * then analytics and compliance — are added by writing another definition
 * here. Neither writer changes, and neither does the queue.
 *
 * The permission a definition names is the same right the report's own
 * endpoint is guarded by: exporting a report is reading it into a file, so
 * whoever may read it may take it away, and whoever may not is refused before
 * a record is written.
 */
final class ReportExportRegistry
{
    public function __construct(
        private readonly FinancialStatementQuery $statements,
        private readonly LedgerQuery $ledger,
        private readonly DashboardQuery $dashboard,
        private readonly ForecastPageQuery $forecast,
    ) {}

    /**
     * @return array<string, ReportDefinition>
     */
    private function definitions(): array
    {
        return [
            'profit_and_loss' => new ReportDefinition(
                type: 'profit_and_loss',
                label: 'Profit and Loss',
                permission: AccessMatrix::PERMISSION_REPORT_VIEW,
                parameters: ['from', 'to', 'compare'],
                build: function (int $companyId, array $parameters): ReportDocument {
                    return $this->profitAndLossDocument($companyId, $parameters);
                },
            ),

            'balance_sheet' => new ReportDefinition(
                type: 'balance_sheet',
                label: 'Balance Sheet',
                permission: AccessMatrix::PERMISSION_REPORT_VIEW,
                parameters: ['as_of', 'compare'],
                build: function (int $companyId, array $parameters): ReportDocument {
                    return $this->balanceSheetDocument($companyId, $parameters);
                },
            ),

            'trial_balance' => new ReportDefinition(
                type: 'trial_balance',
                label: 'Trial Balance',
                permission: AccessMatrix::PERMISSION_REPORT_VIEW,
                parameters: ['from', 'to', 'compare'],
                build: function (int $companyId, array $parameters): ReportDocument {
                    return $this->trialBalanceDocument($companyId, $parameters);
                },
            ),

            // The dashboard as a file. It is the one definition whose figures
            // are a composition rather than one report's answer, so it reads the
            // same service the dashboard endpoint reads — which reads the same
            // services the statements above read. The totals in the export are
            // therefore the totals on the screen by construction, and a test
            // asserts it rather than trusting the paragraph.
            'financial_manager_dashboard' => new ReportDefinition(
                type: 'financial_manager_dashboard',
                label: 'Financial Manager Dashboard',
                permission: AccessMatrix::PERMISSION_REPORT_VIEW,
                parameters: ['from', 'to'],
                build: function (int $companyId, array $parameters): ReportDocument {
                    return $this->dashboardDocument($companyId, $parameters);
                },
            ),

            // The forecast as a file, guarded by the forecast right rather than
            // the report right: reading the books has never meant being shown
            // what the company intends, and the definition names the right the
            // forecast endpoint itself is guarded by, so a caller who cannot
            // read the projection cannot take it away either.
            'forecast' => new ReportDefinition(
                type: 'forecast',
                label: 'Forecast',
                permission: AccessMatrix::PERMISSION_FORECAST_VIEW,
                parameters: ['as_of'],
                build: function (int $companyId, array $parameters): ReportDocument {
                    return $this->forecastDocument($companyId, $parameters);
                },
            ),
        ];
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->definitions());
    }

    /**
     * The types a caller may name, for validation.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->definitions());
    }

    public function resolve(string $type): ReportDefinition
    {
        return $this->definitions()[$type]
            ?? throw UnknownExportTypeException::named($type);
    }

    /**
     * Only the parameters the named report accepts, with empty strings read as
     * absent so a caller who sends `?from=` is not asking for the year zero.
     *
     * @param  array<string, mixed>  $requested
     * @return array<string, mixed>
     */
    public function parametersFor(string $type, array $requested): array
    {
        $definition = $this->resolve($type);
        $parameters = [];

        foreach ($definition->parameters as $name) {
            $value = $requested[$name] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $parameters[$name] = $name === 'compare' ? (bool) $value : (string) $value;
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function profitAndLossDocument(int $companyId, array $parameters): ReportDocument
    {
        $from = $this->string($parameters, 'from');
        $to = $this->string($parameters, 'to');
        $compare = (bool) ($parameters['compare'] ?? false);

        $report = $this->statements->profitAndLoss($companyId, $from, $to, $compare);

        $sections = [
            $this->accountSection('Revenue', $report['revenue']['accounts'], (string) $report['revenue']['total'], 'Total revenue'),
            $this->accountSection('Expense', $report['expense']['accounts'], (string) $report['expense']['total'], 'Total expense'),
        ];

        if (is_array($report['comparison'] ?? null) && is_array($report['variance'] ?? null)) {
            $sections[] = $this->comparisonSection($report['comparison'], $report['variance'], [
                ['Revenue', 'revenue.total', 'revenue'],
                ['Expense', 'expense.total', 'expense'],
                ['Net profit', 'net_profit', 'net_profit'],
            ]);
        }

        return new ReportDocument(
            title: 'Profit and Loss',
            meta: $this->meta($companyId, 'Period', $this->periodLabel($from, $to)),
            sections: $sections,
            summary: [
                ['label' => 'Net profit', 'value' => (string) $report['net_profit']],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function balanceSheetDocument(int $companyId, array $parameters): ReportDocument
    {
        $asOf = $this->string($parameters, 'as_of');
        $compare = (bool) ($parameters['compare'] ?? false);

        $report = $this->statements->balanceSheet($companyId, $asOf, $compare);

        $equityAccounts = $report['equity']['accounts'];
        $unclosedResult = (string) $report['equity']['unclosed_result'];

        // The unclosed result is a figure of the statement rather than a row of
        // the chart, so it is added to the equity block by hand — exactly as the
        // report itself does when it makes the two sides balance.
        $equityRows = $this->accountRows($equityAccounts);

        if (Money::of($unclosedResult)->isZero() === false) {
            $equityRows[] = ['', 'Unclosed result', $unclosedResult];
        }

        $sections = [
            $this->accountSection('Assets', $report['assets']['accounts'], (string) $report['assets']['total'], 'Total assets'),
            $this->accountSection('Liabilities', $report['liabilities']['accounts'], (string) $report['liabilities']['total'], 'Total liabilities'),
            new ReportSection(
                heading: 'Equity',
                columns: ['Code', 'Account', 'Amount'],
                rows: $equityRows,
                total: ['', 'Total equity', (string) $report['equity']['total']],
            ),
        ];

        if (is_array($report['comparison'] ?? null) && is_array($report['variance'] ?? null)) {
            $sections[] = $this->comparisonSection($report['comparison'], $report['variance'], [
                ['Assets', 'total_assets', 'assets'],
                ['Liabilities', 'liabilities.total', 'liabilities'],
                ['Equity', 'equity.total', 'equity'],
            ]);
        }

        return new ReportDocument(
            title: 'Balance Sheet',
            meta: $this->meta($companyId, 'As at', (string) $report['as_of']),
            sections: $sections,
            summary: [
                ['label' => 'Total assets', 'value' => (string) $report['total_assets']],
                ['label' => 'Total liabilities and equity', 'value' => (string) $report['total_liabilities_and_equity']],
                ['label' => 'Balanced', 'value' => $report['balanced'] ? 'Yes' : 'No'],
            ],
            note: $report['note'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function trialBalanceDocument(int $companyId, array $parameters): ReportDocument
    {
        $from = $this->string($parameters, 'from');
        $to = $this->string($parameters, 'to');
        $compare = (bool) ($parameters['compare'] ?? false);

        // Every account that moved, not the first page of them: an export is
        // the whole report, and a reader who asked for a file did not ask for
        // page one of it. The service totals the whole set either way, so the
        // totals below are the endpoint's own.
        $report = $this->ledger->trialBalance($companyId, $from, $to, 1, PHP_INT_MAX, $compare);

        $rows = [];

        foreach ($report['data'] as $row) {
            $rows[] = [
                (string) $row['code'],
                (string) $row['name'],
                (string) $row['account_type'],
                (string) $row['debit'],
                (string) $row['credit'],
                (string) $row['net'],
            ];
        }

        $totalDebit = Money::of((string) $report['meta']['total_debit']);
        $totalCredit = Money::of((string) $report['meta']['total_credit']);

        $sections = [
            new ReportSection(
                heading: 'Accounts',
                columns: ['Code', 'Account', 'Type', 'Debit', 'Credit', 'Net'],
                rows: $rows,
                total: [
                    '',
                    'Totals',
                    '',
                    $totalDebit->toDecimal(),
                    $totalCredit->toDecimal(),
                    $totalDebit->minus($totalCredit)->toDecimal(),
                ],
            ),
        ];

        if (is_array($report['meta']['comparison'] ?? null) && is_array($report['meta']['variance'] ?? null)) {
            $sections[] = $this->comparisonSection($report['meta']['comparison'], $report['meta']['variance'], [
                ['Total debit', 'total_debit', 'total_debit'],
                ['Total credit', 'total_credit', 'total_credit'],
            ]);
        }

        return new ReportDocument(
            title: 'Trial Balance',
            meta: $this->meta($companyId, 'Period', $this->periodLabel($from, $to)),
            sections: $sections,
            summary: [
                ['label' => 'Total debit', 'value' => $totalDebit->toDecimal()],
                ['label' => 'Total credit', 'value' => $totalCredit->toDecimal()],
                ['label' => 'Balanced', 'value' => $report['meta']['balanced'] ? 'Yes' : 'No'],
            ],
        );
    }

    /**
     * The dashboard, flattened into the same document shape as the statements.
     *
     * The projection block is headed "FORECAST — NOT ACTUAL" and carries its own
     * total line, so a reader of the file can never mistake a projected figure
     * for a posted one. A block the dashboard could not read is written as a
     * line saying so rather than left out, because a section silently missing
     * from an export is a section a reader would take to be empty.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function dashboardDocument(int $companyId, array $parameters): ReportDocument
    {
        $from = $this->string($parameters, 'from');
        $to = $this->string($parameters, 'to');

        $report = $this->dashboard->dashboard($companyId, $from, $to);
        $sections = is_array($report['sections'] ?? null) ? $report['sections'] : [];
        $window = is_array($report['window'] ?? null) ? $report['window'] : [];

        $kpis = $this->block($sections, 'kpis');
        $ageing = $this->block($sections, 'ageing');
        $reconciliation = $this->block($sections, 'reconciliation');
        $forecast = $this->block($sections, 'forecast');

        $summary = [];
        $cards = [];

        foreach ($this->part($kpis, 'cards') as $card) {
            if (! is_array($card)) {
                continue;
            }

            $percent = $card['variance_percent'] ?? null;

            $cards[] = [
                (string) ($card['label'] ?? ''),
                (string) ($card['value'] ?? ''),
                (string) ($card['prior'] ?? ''),
                (string) ($card['variance'] ?? ''),
                $percent === null ? '' : $percent.'%',
            ];

            $summary[] = [
                'label' => (string) ($card['label'] ?? ''),
                'value' => (string) ($card['value'] ?? ''),
            ];
        }

        $documents = [
            new ReportSection(
                heading: 'Executive measures',
                columns: ['Measure', 'Actual', 'Prior', 'Variance', 'Variance %'],
                rows: $cards,
            ),
            new ReportSection(
                heading: 'Receivables ageing',
                columns: ['Age', 'Amount'],
                rows: $this->bucketRows($this->part($ageing, 'receivables')['totals'] ?? []),
                total: ['Total', $this->ageingTotal($ageing, 'receivables')],
            ),
            new ReportSection(
                heading: 'Payables ageing',
                columns: ['Age', 'Amount'],
                rows: $this->bucketRows($this->part($ageing, 'payables')['totals'] ?? []),
                total: ['Total', $this->ageingTotal($ageing, 'payables')],
            ),
            new ReportSection(
                heading: 'Reconciliation',
                columns: ['Check', 'Expected', 'Actual', 'Difference', 'Reconciled'],
                rows: $this->reconciliationRows($reconciliation),
            ),
            new ReportSection(
                heading: 'FORECAST — NOT ACTUAL',
                columns: ['Period', 'Projected revenue', 'Projected expense', 'Projected net'],
                rows: $this->forecastRows($forecast),
                total: $this->forecastTotal($forecast),
            ),
        ];

        return new ReportDocument(
            title: 'Financial Manager Dashboard',
            meta: $this->meta($companyId, 'Period', $this->periodLabel(
                is_string($window['from'] ?? null) ? $window['from'] : $from,
                is_string($window['to'] ?? null) ? $window['to'] : $to,
            )),
            sections: $documents,
            summary: $summary,
            note: 'The projection block is labelled FORECAST — NOT ACTUAL. Nothing in it is posted: '
                .'the ledger does not know a forecast exists, and no projected figure is mixed with a '
                .'posted one. Every actual figure is the posted ledger, read through the same reporting '
                .'services the statements use.',
        );
    }

    /**
     * The forecast page flattened into the same document shape as the
     * statements.
     *
     * Every block is read from the same ForecastPageQuery the forecast screen
     * reads, for the same company and date, so the file and the screen are the
     * same answer read once more and cannot disagree. The projection block is
     * headed "FORECAST — NOT ACTUAL" and carries its own total line, and the
     * cash and working-capital blocks are headed as estimates, so a reader of
     * the file can never mistake a projection for a posted figure.
     *
     * A scenario is deliberately not offered here. An export is a file that
     * outlives the request that asked for it, and a set of assumptions handed
     * to a read has no standing to be kept: the file holds the company's own
     * projection, stated under the assumptions the screen states. A block the
     * page could not read is written as a line saying so rather than left out,
     * because a section silently missing from an export is a section a reader
     * would take to be empty.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function forecastDocument(int $companyId, array $parameters): ReportDocument
    {
        $asOf = $this->string($parameters, 'as_of');

        $page = $this->forecast->page(
            $companyId,
            $asOf,
            ['name' => 'base', 'revenue_growth' => '0', 'expense_growth' => '0', 'adjustments' => []],
            false,
        );

        $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
        $window = is_array($page['window'] ?? null) ? $page['window'] : [];

        $projection = $this->block($sections, 'projection');
        $kpis = $this->block($sections, 'kpis');
        $cash = $this->block($sections, 'cash_outlook');
        $capital = $this->block($sections, 'working_capital');
        $risk = $this->block($sections, 'risk_alerts');

        $cards = [];
        $summary = [];

        foreach ($this->part($kpis, 'cards') as $card) {
            if (! is_array($card)) {
                continue;
            }

            $percent = $card['variance_percent'] ?? null;

            $cards[] = [
                (string) ($card['label'] ?? ''),
                (string) ($card['forecast'] ?? ''),
                (string) ($card['budget'] ?? ''),
                (string) ($card['variance'] ?? ''),
                $percent === null ? '' : $percent.'%',
            ];

            $summary[] = [
                'label' => (string) ($card['label'] ?? ''),
                'value' => (string) ($card['forecast'] ?? ''),
            ];
        }

        $documents = [
            new ReportSection(
                heading: 'FORECAST — NOT ACTUAL',
                columns: ['Period', 'Projected revenue', 'Projected expense', 'Projected net'],
                rows: $this->forecastRows($projection),
                total: $this->forecastTotal($projection),
            ),
            new ReportSection(
                heading: 'Executive measures',
                columns: ['Measure', 'Forecast', 'Budget', 'Variance', 'Variance %'],
                rows: $cards,
            ),
            new ReportSection(
                heading: 'Cash outlook (estimate)',
                columns: ['Measure', 'Amount'],
                rows: $this->figureRows($cash, [
                    'opening_cash' => 'Opening cash',
                    'projected_net_profit' => 'Projected net profit',
                    'expected_collections' => 'Expected collections',
                    'expected_payments' => 'Expected payments',
                    'projected_closing_cash' => 'Projected closing cash',
                ]),
            ),
            new ReportSection(
                heading: 'Working capital (estimate)',
                columns: ['Measure', 'Amount'],
                rows: $this->figureRows($capital, [
                    'receivables' => 'Receivables',
                    'payables' => 'Payables',
                    'projected_receivables' => 'Projected receivables',
                    'projected_payables' => 'Projected payables',
                    'projected_working_capital' => 'Projected working capital',
                    'projected_cash' => 'Projected cash',
                ]),
            ),
            new ReportSection(
                heading: 'Risk alerts',
                columns: ['Level', 'Alert', 'Detail', 'Amount'],
                rows: $this->alertRows($risk),
            ),
        ];

        $from = is_string($window['from_period'] ?? null) ? $window['from_period'] : null;
        $to = is_string($window['to_period'] ?? null) ? $window['to_period'] : null;

        return new ReportDocument(
            title: 'Forecast',
            meta: $this->meta($companyId, 'Horizon', $this->periodLabel($from, $to)),
            sections: $documents,
            summary: $summary,
            note: 'Every figure in this file is a projection and not a posted result. Nothing here is '
                .'stored, no journal is posted, and no forecast figure appears in a trial balance or any '
                .'other actual report. The projection is read from the same service the forecast screen '
                .'reads, for the same company and date.',
        );
    }

    /**
     * Named figures out of a block as label/value rows, in the order given. A
     * figure the block did not state reads as an empty cell rather than as a
     * zero, so an absent answer is not written as a figure.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $labels  payload key => written label
     * @return list<list<string>>
     */
    private function figureRows(array $source, array $labels): array
    {
        $rows = [];

        foreach ($labels as $key => $label) {
            $value = $source[$key] ?? null;

            $rows[] = [$label, is_scalar($value) ? (string) $value : ''];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $risk
     * @return list<list<string>>
     */
    private function alertRows(array $risk): array
    {
        $rows = [];

        foreach ($this->part($risk, 'alerts') as $alert) {
            if (! is_array($alert)) {
                continue;
            }

            $rows[] = [
                (string) ($alert['level'] ?? ''),
                (string) ($alert['title'] ?? ''),
                (string) ($alert['detail'] ?? ''),
                (string) ($alert['amount'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * One block of the dashboard payload, or an empty array when it could not be
     * read or its section is absent.
     *
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function block(array $sections, string $key): array
    {
        $section = $sections[$key] ?? null;

        if (! is_array($section) || ! is_array($section['data'] ?? null)) {
            return [];
        }

        return $section['data'];
    }

    /**
     * A named part of a block, or an empty array when the block did not state
     * it. Kept as the block holds it — a part may be a list of rows or a
     * mapping of names to figures, and both are read the same way here.
     *
     * @param  array<string, mixed>  $source
     * @return array<array-key, mixed>
     */
    private function part(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * The five age bands an ageing report states, in its own order.
     *
     * @return list<list<string>>
     */
    private function bucketRows(mixed $totals): array
    {
        if (! is_array($totals)) {
            return [];
        }

        $rows = [];

        foreach ([
            'current' => 'Current',
            '1_30' => '1–30 days',
            '31_60' => '31–60 days',
            '61_90' => '61–90 days',
            'over_90' => 'Over 90 days',
        ] as $key => $label) {
            $rows[] = [$label, (string) ($totals[$key] ?? '')];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $ageing
     */
    private function ageingTotal(array $ageing, string $side): string
    {
        $totals = $this->part($ageing, $side)['totals'] ?? null;

        return is_array($totals) ? (string) ($totals['total'] ?? '') : '';
    }

    /**
     * @param  array<string, mixed>  $reconciliation
     * @return list<list<string>>
     */
    private function reconciliationRows(array $reconciliation): array
    {
        $rows = [];

        foreach ($this->part($reconciliation, 'checks') as $check) {
            if (! is_array($check)) {
                continue;
            }

            $rows[] = [
                (string) ($check['label'] ?? ''),
                (string) ($check['expected_label'] ?? '').': '.(string) ($check['expected'] ?? ''),
                (string) ($check['actual_label'] ?? '').': '.(string) ($check['actual'] ?? ''),
                (string) ($check['difference'] ?? ''),
                ($check['reconciled'] ?? false) === true ? 'Yes' : 'No',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $forecast
     * @return list<list<string>>
     */
    private function forecastRows(array $forecast): array
    {
        $rows = [];

        foreach ($this->part($forecast, 'periods') as $period) {
            if (! is_array($period)) {
                continue;
            }

            // A period that has already happened belongs in the year's story but
            // nothing was projected onto it, so it is written as its name with
            // the three projected columns blank rather than as a row of zeroes
            // that would read as a forecast.
            if (($period['is_forecast'] ?? false) !== true) {
                $rows[] = [(string) ($period['period']['name'] ?? ''), '', '', ''];

                continue;
            }

            $rows[] = [
                (string) ($period['period']['name'] ?? ''),
                (string) ($period['revenue']['forecast'] ?? ''),
                (string) ($period['expense']['forecast'] ?? ''),
                (string) ($period['net']['forecast'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $forecast
     * @return list<string>|null
     */
    private function forecastTotal(array $forecast): ?array
    {
        $totals = $this->part($forecast, 'horizon_totals')['net'] ?? null;

        if (! is_array($totals)) {
            return null;
        }

        return ['Projected total — not actual', '', '', (string) ($totals['forecast'] ?? '')];
    }

    /**
     * A section of chart accounts and the line that totals it.
     *
     * @param  list<array<string, mixed>>  $accounts
     */
    private function accountSection(string $heading, array $accounts, string $total, string $totalLabel): ReportSection
    {
        return new ReportSection(
            heading: $heading,
            columns: ['Code', 'Account', 'Amount'],
            rows: $this->accountRows($accounts),
            total: ['', $totalLabel, $total],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return list<list<string>>
     */
    private function accountRows(array $accounts): array
    {
        $rows = [];

        foreach ($accounts as $account) {
            $rows[] = [
                (string) $account['code'],
                (string) $account['name'],
                (string) $account['amount'],
            ];
        }

        return $rows;
    }

    /**
     * The prior column as a section of its own: the same measures the report
     * above just stated, read at the earlier date or over the earlier window,
     * with the difference beside them.
     *
     * The report computes the variance, so it is read rather than subtracted
     * here. A second copy of that arithmetic would be a second place to get it
     * wrong, and a positive variance means an increase, which is the report's
     * convention to state and not the writer's to rediscover.
     *
     * @param  array<string, mixed>  $comparison
     * @param  array<string, mixed>  $variance
     * @param  list<array{0: string, 1: string, 2: string}>  $measures  label, comparison path, variance key
     */
    private function comparisonSection(array $comparison, array $variance, array $measures): ReportSection
    {
        $rows = [];

        foreach ($measures as [$label, $path, $varianceKey]) {
            $rows[] = [
                $label,
                (string) $this->dig($comparison, $path),
                (string) ($variance[$varianceKey] ?? ''),
            ];
        }

        return new ReportSection(
            heading: 'Comparison with prior period',
            columns: ['Measure', 'Prior', 'Variance'],
            rows: $rows,
        );
    }

    /**
     * Read a dotted path out of the report's own payload, so a comparison
     * figure is the report's answer rather than a second sum over the ledger.
     *
     * @param  array<string, mixed>  $source
     */
    private function dig(array $source, string $path): mixed
    {
        $value = $source;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function meta(int $companyId, string $dateLabel, string $dateValue): array
    {
        return [
            ['label' => 'Company', 'value' => $this->companyName($companyId)],
            ['label' => $dateLabel, 'value' => $dateValue],
        ];
    }

    private function companyName(int $companyId): string
    {
        return (string) (Company::query()->whereKey($companyId)->value('name') ?? '');
    }

    private function periodLabel(?string $from, ?string $to): string
    {
        return match (true) {
            $from !== null && $to !== null => $from.' to '.$to,
            $from !== null => 'From '.$from,
            $to !== null => 'Up to '.$to,
            default => 'All dates',
        };
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function string(array $parameters, string $key): ?string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
