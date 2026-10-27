<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\AgeingQuery;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
