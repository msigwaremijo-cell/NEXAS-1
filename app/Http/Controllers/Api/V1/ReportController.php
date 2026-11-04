<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessMatrix;
use App\Domain\Reporting\Services\AgeingQuery;
use App\Domain\Reporting\Services\BudgetVarianceQuery;
use App\Domain\Reporting\Services\CashFlowQuery;
use App\Domain\Reporting\Services\DashboardQuery;
use App\Domain\Reporting\Services\FinancialStatementQuery;
use App\Domain\Reporting\Services\ForecastPageQuery;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Domain\Reporting\Services\LedgerVerification;
use App\Domain\Reporting\Services\StockValuationQuery;
use App\Domain\Reporting\Services\VatReturnQuery;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\FiscalPeriod;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ledger reports, read only.
 *
 * Nothing is computed here. Which lines count as posted, where a running
 * balance starts and how a trial balance is totalled are accounting rules and
 * live in app/Domain/Reporting/Services/LedgerQuery. This class validates the
 * request, authorises it against the account it names, and serialises the
 * answer.
 *
 * The company is never taken from the query string: it comes from the `company`
 * middleware, which has already proved the caller is a member of it, so a
 * report cannot be pointed at another tenant's books by changing a parameter.
 */
class ReportController extends Controller
{
    public function generalLedger(Request $request, LedgerQuery $ledger): JsonResponse
    {
        // Asked before the request is read, like every other route. A member
        // without report.view is refused because of that, rather than because
        // of the shape of a request they were never entitled to make, and no
        // input is parsed and no account is looked up on their behalf.
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->companyId($request);

        // Scoped to the company in the same query that resolves the account, so
        // asking for another tenant's account is a 404 and not a leak.
        $account = Account::query()
            ->where('company_id', $companyId)
            ->whereKey($filters['account_id'])
            ->firstOrFail();

        $this->authorize('view', $account);

        [$from, $to] = $this->range($filters);

        return response()->json($ledger->generalLedger(
            $companyId,
            $account,
            $from,
            $to,
            max($request->integer('page', 1), 1),
            min(max($request->integer('per_page', 50), 1), 100),
        ));
    }

    public function trialBalance(Request $request, LedgerQuery $ledger): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        [$from, $to] = $this->range($filters);

        return response()->json($ledger->trialBalance(
            $this->companyId($request),
            $from,
            $to,
            max($request->integer('page', 1), 1),
            min(max($request->integer('per_page', 50), 1), 100),
            $request->boolean('compare'),
        ));
    }

    public function receivablesAgeing(Request $request, AgeingQuery $ageing): JsonResponse
    {
        // Ageing is a report about the books, so it is guarded by the same
        // report permission as the ledger and the trial balance rather than by
        // the invoice permission: reading how late a customer is does not mean
        // being allowed to raise or edit their invoices.
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $ageing->receivables($this->companyId($request), $filters['as_of'] ?? null),
        ]);
    }

    public function payablesAgeing(Request $request, AgeingQuery $ageing): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $ageing->payables($this->companyId($request), $filters['as_of'] ?? null),
        ]);
    }

    /**
     * Where the money came from and where it went, over a period.
     *
     * Guarded by the same report permission as the ledger and the ageing
     * reports: reading the company's cash movements is reading the books.
     */
    public function cashFlow(Request $request, CashFlowQuery $cashFlow): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        [$from, $to] = $this->range($filters);

        return response()->json($cashFlow->statement(
            $this->companyId($request),
            $from,
            $to,
            $request->boolean('compare'),
        ));
    }

    /**
     * The same period's cash movement, read the indirect way: from the profit
     * down to the money.
     *
     * A second route rather than a flag on `cashFlow()`, because the two are
     * different statements about one period rather than one statement shown two
     * ways: a reader asks for the reconciliation or for the activities, and the
     * window, the comparison and the permission are the same for both.
     *
     * Guarded by the same report permission as the direct statement beside it.
     */
    public function cashFlowIndirect(Request $request, CashFlowQuery $cashFlow): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        [$from, $to] = $this->range($filters);

        return response()->json($cashFlow->statementIndirect(
            $this->companyId($request),
            $from,
            $to,
            $request->boolean('compare'),
        ));
    }

    /**
     * What is in each cash and bank account as at a date, and the total.
     *
     * The date is named `as_of` like the ageing reports', because this is a
     * standing figure rather than a movement over a range.
     */
    public function cashPosition(Request $request, CashFlowQuery $cashFlow): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $cashFlow->position($this->companyId($request), $filters['as_of'] ?? null)
        );
    }

    /**
     * What the stock on hand is worth as at a date, and whether the inventory
     * accounts in the ledger say the same.
     *
     * Guarded by the stock read right rather than the report right: this is a
     * report about what is on the shelves, and whoever may read the movements
     * is who may read what they are worth.
     */
    public function stockValuation(Request $request, StockValuationQuery $valuation): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $filters = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $valuation->valuation($this->companyId($request), $filters['as_of'] ?? null)
        );
    }

    /**
     * Revenue less expense over a period.
     *
     * Guarded by the same report permission as the ledger beside it: the
     * statement is the ledger's temporary accounts read a second way, so a
     * member who may read one is who may read the other.
     */
    public function profitAndLoss(Request $request, FinancialStatementQuery $statements): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        [$from, $to] = $this->range($filters);

        return response()->json(
            $statements->profitAndLoss($this->companyId($request), $from, $to, $request->boolean('compare'))
        );
    }

    /**
     * What the company owns and what it owes as at a date.
     *
     * The date is named `as_of` like the ageing and cash position reports',
     * because this is a standing figure rather than a movement over a range.
     */
    public function balanceSheet(Request $request, FinancialStatementQuery $statements): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            $statements->balanceSheet(
                $this->companyId($request),
                $filters['as_of'] ?? null,
                $request->boolean('compare'),
            )
        );
    }

    /**
     * Whether the ledger_balances snapshot still agrees with the ledger.
     *
     * A read-only check: nothing is repaired here. It is exposed so the same
     * answer the nightly `ledger:verify` command reports can be read from the
     * application, which is what makes a drift visible to somebody who is not
     * watching a cron mailbox.
     *
     * Guarded by the report right, because what it reports is whether the books
     * the reports read still agree with themselves. Named by fiscal period
     * optionally, scoped to the caller's company, so another tenant's period is
     * a 404 rather than a leak.
     */
    public function ledgerVerification(Request $request, LedgerVerification $verification): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'fiscal_period' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $companyId = $this->companyId($request);
        $fiscalPeriodId = null;

        if (! empty($filters['fiscal_period'])) {
            $period = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('ulid', $filters['fiscal_period'])
                ->firstOrFail();

            $fiscalPeriodId = (int) $period->getKey();
        }

        return response()->json($verification->verify($companyId, $fiscalPeriodId));
    }

    /**
     * The VAT3 return for a period: what the company charged, what it was
     * charged, and what is left to pay.
     *
     * The period may be named by its fiscal period, which is how a return is
     * actually filed, or by an explicit range. Naming the period is preferred
     * because the calendar, not the caller, decides where a month begins: the
     * period resolves to its own `starts_on` and `ends_on`. The lookup is
     * scoped to the caller's company, so a period belonging to another tenant
     * is a 404 rather than a leak.
     *
     * Guarded by the same report permission as the ledger beside it: a return
     * is the tax accounts of the ledger read a second way.
     */
    public function vatReturn(Request $request, VatReturnQuery $vat): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'fiscal_period' => ['sometimes', 'nullable', 'string', 'size:26'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $companyId = $this->companyId($request);
        $period = null;

        if (! empty($filters['fiscal_period'])) {
            $period = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('ulid', $filters['fiscal_period'])
                ->firstOrFail();

            [$from, $to] = [$period->starts_on->toDateString(), $period->ends_on->toDateString()];
        } else {
            [$from, $to] = $this->range($filters);
        }

        $report = $vat->vatReturn($companyId, $from, $to);

        if ($period !== null) {
            $report['fiscal_period'] = [
                'ulid' => $period->ulid,
                'name' => $period->name,
                'period_no' => (int) $period->period_no,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
            ];
        }

        return response()->json($report);
    }

    /**
     * The plan for a period beside what the period actually did.
     *
     * Guarded by the budget read right rather than the report right the
     * statements. The two columns come from two different places — the actual
     * is the posted ledger read through the same profit and loss statement as
     * the report above, and the budget is what the company intends, which
     * writing posts no journal for. Reading the books has never meant being
     * shown the plan, so a member who may read the statements is not thereby
     * handed this.
     *
     * Named by fiscal period rather than by a hand-picked range, because a
     * plan is written for a period and only a period can have one. The lookup
     * is scoped to the caller's company, so another tenant's period is a 404
     * rather than a leak.
     */
    public function budgetVsActual(Request $request, BudgetVarianceQuery $variance): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $filters = $request->validate([
            'fiscal_period' => ['required', 'string', 'size:26'],
            'compare' => ['sometimes', 'boolean'],
        ]);

        $companyId = $this->companyId($request);

        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('ulid', $filters['fiscal_period'])
            ->firstOrFail();

        return response()->json(
            $variance->report($companyId, $period, $request->boolean('compare'))
        );
    }

    /**
     * The Financial Manager forecast page in one read.
     *
     * A forecast is built from the plan where the company wrote one for the
     * period and from the trailing run-rate where it did not, so it is guarded
     * by `forecast.view` rather than the report right the statements use.
     * Reading the books has never meant being shown what the company intends,
     * and a projection that is half plan is still half plan.
     *
     * Running the projection under different assumptions is a second, narrower
     * right. A request that carries any scenario parameter at all is refused
     * unless the caller holds `forecast.scenario`, and the refusal happens
     * before the parameters are read: what a caller unable to ask "what if" is
     * sent is not parsed, validated and then discarded, it is not parsed. The
     * right is checked on the presence of a parameter rather than on its value,
     * so a scenario named `base` is still a scenario being run.
     *
     * Nothing is stored and nothing is posted. The payload is a composition of
     * sections, each produced independently, so one read that cannot be served
     * answers for itself and leaves the rest of the page standing. The response
     * echoes the assumptions back only after they have been validated and
     * normalised, never as they were received.
     */
    public function forecast(
        Request $request,
        ForecastPageQuery $page,
    ): JsonResponse {
        $this->authorize(AccessMatrix::PERMISSION_FORECAST_VIEW);

        if ($this->asksForAScenario($request)) {
            $this->authorize(AccessMatrix::PERMISSION_FORECAST_SCENARIO);
        }

        $filters = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'scenario' => ['sometimes', 'nullable', Rule::in(array_keys((array) config('nexas.forecast.scenarios')))],
            'revenue_growth' => $this->growthRules(),
            'expense_growth' => $this->growthRules(),
            'adjustments' => ['sometimes', 'array', 'max:'.(int) config('nexas.forecast.max_adjustments', 50)],
            'adjustments.*.account_id' => ['required', 'integer', 'min:1'],
            'adjustments.*.amount' => ['required', 'regex:/^-?\d+(\.\d{1,4})?$/'],
        ]);

        return response()->json($page->page(
            $this->companyId($request),
            $filters['as_of'] ?? null,
            $this->scenario($filters),
            $request->user()?->can(AccessMatrix::PERMISSION_FORECAST_SCENARIO) === true,
        ));
    }

    /**
     * Whether the request is a scenario run at all.
     *
     * Asked of the presence of a parameter and not of its value, because the
     * question is whether the caller is testing an assumption rather than
     * reading the projection, and a growth of zero is still a growth the caller
     * chose.
     */
    private function asksForAScenario(Request $request): bool
    {
        foreach (['scenario', 'revenue_growth', 'expense_growth', 'adjustments'] as $parameter) {
            if ($request->exists($parameter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A growth rate is taken as a plain decimal — a percentage, so `10` is ten
     * per cent — and bounded by the envelope in configuration. The rule refuses
     * scientific notation and anything with more than four decimal places, so
     * what reaches the money arithmetic is a literal it can accept.
     *
     * `numeric` is stated explicitly rather than left to `between`: without it
     * `between` measures a string's length, so `5000` would pass an envelope
     * that names 1000 as its ceiling by being four characters long. It is the
     * numeric rule that makes the bound a bound.
     *
     * @return list<string>
     */
    private function growthRules(): array
    {
        $max = (string) config('nexas.forecast.max_growth_percent', '1000');

        return [
            'sometimes',
            'nullable',
            'numeric',
            'regex:/^-?\d+(\.\d{1,4})?$/',
            'between:-'.$max.','.$max,
        ];
    }

    /**
     * The assumptions the projection is asked for, normalised.
     *
     * A named scenario starts from the rates configured for it and either rate
     * may be replaced on the request; an unnamed one starts from zero, which is
     * the projection the company actually expects. The adjustments are keyed by
     * account so a repeated account resolves to one figure rather than being
     * applied twice.
     *
     * @param  array<string, mixed>  $filters
     * @return array{name: string, revenue_growth: string, expense_growth: string, adjustments: array<int, string>}
     */
    private function scenario(array $filters): array
    {
        $name = (string) ($filters['scenario'] ?? 'base');
        $rates = config('nexas.forecast.scenarios.'.$name);
        $rates = is_array($rates) ? $rates : [];

        $adjustments = [];

        foreach ($filters['adjustments'] ?? [] as $adjustment) {
            // The account is only ever matched against rows the projection has
            // already produced for this company, so an account the caller does
            // not own adjusts nothing and reads nothing.
            $adjustments[(int) $adjustment['account_id']] = (string) $adjustment['amount'];
        }

        return [
            'name' => $name,
            'revenue_growth' => (string) ($filters['revenue_growth'] ?? $rates['revenue_growth'] ?? '0'),
            'expense_growth' => (string) ($filters['expense_growth'] ?? $rates['expense_growth'] ?? '0'),
            'adjustments' => $adjustments,
        ];
    }

    /**
     * The whole Financial Manager dashboard in one read.
     *
     * A composition rather than a report: every figure on the screen is another
     * report's answer, read once for one company and one window by
     * DashboardQuery. The window is the same `from`/`to` pair every other
     * report takes, so the Month, Quarter, Year and Custom filters send the same
     * thing here as they do to the report a card links through to.
     *
     * Guarded by the report read right, which is what every statement on it is
     * guarded by. The company comes from the `company` middleware and never
     * from a parameter, so the dashboard cannot be pointed at another tenant's
     * books.
     *
     * A section that cannot be read does not fail the request: it answers with
     * its own error and the rest of the payload is still returned, which is what
     * lets one failing read leave the page standing.
     */
    public function dashboard(Request $request, DashboardQuery $dashboard): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        [$from, $to] = $this->range($filters);

        return response()->json($dashboard->dashboard($this->companyId($request), $from, $to));
    }

    /**
     * A range that runs backwards would silently report nothing, so it is
     * refused rather than honoured.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: ?string, 1: ?string}
     */
    private function range(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages([
                'from' => ['The from date must not be later than the to date.'],
            ]);
        }

        return [$from, $to];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
