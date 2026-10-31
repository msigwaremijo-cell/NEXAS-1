<?php

namespace App\Domain\Reporting\Exports;

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Exceptions\UnknownExportTypeException;
use App\Domain\Reporting\Services\FinancialStatementQuery;
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
