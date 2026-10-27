<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The chart of accounts, read only.
 *
 * Accounts are master data: they are created by the seeder that ships the
 * Tanzanian chart and are edited through a separate maintenance screen, so
 * there is no write here. This endpoint exists so the journal entry screen and
 * the reports can page through the accounts the caller is actually allowed to
 * post to.
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
