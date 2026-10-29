<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Actions\SetCashFlowCategory;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts.
 *
 * Accounts are master data: they are created by the seeder that ships the
 * Tanzanian chart, so there is no create or delete here. The one thing this
 * surface does change is the cash flow classification, which is a decision
 * about how the books are presented rather than a posting, and is therefore
 * carried out by SetCashFlowCategory — the single audited door onto that
 * column — rather than by an update on the model.
 */
class AccountController extends Controller
{
    public function index(Request $request, LedgerQuery $ledger): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::enum(AccountType::class)],
            'postable' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(max($request->integer('per_page', 50), 1), 100);

        $accounts = $ledger->chartOfAccounts($this->companyId($request), $filters, $perPage);

        return response()->json([
            'data' => array_map(
                fn (Account $account): array => $this->present($account),
                $accounts->items()
            ),
            'meta' => [
                'current_page' => $accounts->currentPage(),
                'per_page' => $accounts->perPage(),
                'total' => $accounts->total(),
                'last_page' => $accounts->lastPage(),
            ],
        ]);
    }

    /**
     * Classify an account on the cash flow statement, or clear the
     * classification by sending null.
     */
    public function updateCashFlowCategory(
        Request $request,
        string $account,
        SetCashFlowCategory $set,
    ): JsonResponse {
        $companyId = $this->companyId($request);

        // Scoped to the company in the same query that resolves the account, so
        // naming another tenant's account is a 404 rather than a 403 that would
        // confirm it exists.
        $model = Account::query()
            ->where('company_id', $companyId)
            ->where('ulid', $account)
            ->firstOrFail();

        $this->authorize('manage', $model);

        $data = $request->validate([
            // `present` rather than `required`: clearing the classification is
            // a decision too, and it has to be said with an explicit null
            // rather than by leaving the key out.
            'cash_flow_category' => ['present', 'nullable', Rule::enum(CashFlowCategory::class)],
        ]);

        $category = $data['cash_flow_category'] === null
            ? null
            : CashFlowCategory::from($data['cash_flow_category']);

        return response()->json(['data' => $this->present($set($model, $category))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Account $account): array
    {
        return [
            'id' => (int) $account->getKey(),
            'ulid' => $account->ulid,
            'company_id' => (int) $account->company_id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type->value,
            'normal_balance' => $account->normal_balance->value,
            'parent_id' => $account->parent_id === null ? null : (int) $account->parent_id,
            'depth' => (int) $account->depth,
            'is_postable' => (bool) $account->is_postable,
            'is_cash' => (bool) $account->is_cash,
            'is_bank' => (bool) $account->is_bank,
            'cash_flow_category' => $account->cash_flow_category?->value,
            'currency_code' => $account->currency_code,
            'status' => $account->status,
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
