<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\OpenBankAccount;
use App\Domain\Banking\Enums\BankAccountStatus;
use App\Domain\Banking\Enums\BankAccountType;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The cash and bank accounts the company keeps money in.
 *
 * Nothing here decides how much is in an account. The balance shown is read out
 * of the ledger through the account's linked ledger account, so this surface can
 * never disagree with the trial balance.
 *
 * `account_id` is checked against the caller's own company rather than merely
 * existing, and the opening balance is handed to OpenBankAccount untouched, so
 * the journal carrying it is written by the same action every other posting goes
 * through.
 *
 * No account on this surface is connected to a real bank. `bank_name` and
 * `account_number` are labels a human typed, and the balances come from the
 * books, not from a bank feed.
 */
class BankAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BankAccount::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_type' => ['sometimes', 'nullable', Rule::enum(BankAccountType::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(BankAccountStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $accounts = BankAccount::query()
            ->where('company_id', $this->companyId($request))
            // The whole ledger account, not a column subset: the ledger reader
            // needs account_type and normal_balance to decide which side a
            // balance falls on, and a partial select would hand it nulls.
            ->with('ledgerAccount')
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->search($filters['search'])
            )
            ->when(
                filled($filters['account_type'] ?? null),
                fn ($query) => $query->where('account_type', $filters['account_type'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (BankAccount $account): array => $this->present($account),
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

    public function store(Request $request, OpenBankAccount $open): JsonResponse
    {
        $this->authorize('create', BankAccount::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('bank_accounts', 'name')->where('company_id', $companyId),
            ],
            'account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'account_type' => ['sometimes', 'string', Rule::enum(BankAccountType::class)],
            'branch_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'exists:currencies,code'],
            'opening_balance' => ['sometimes', 'nullable', 'string', 'max:32'],
            'opening_balance_account_id' => [
                'required_with:opening_balance', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'opening_date' => ['required_with:opening_balance', 'date_format:Y-m-d'],
        ]);

        $account = $open(
            $companyId,
            $data,
            $request->user()?->getKey(),
            $request->header('Idempotency-Key'),
        );

        return response()->json(['data' => $this->present($account)], 201);
    }

    public function show(Request $request, string $bankAccount, LedgerQuery $ledger): JsonResponse
    {
        $companyId = $this->companyId($request);

        // Scoped to the company in the same query that resolves the account, so
        // naming another tenant's account is a 404 rather than a 403 that would
        // confirm it exists.
        $account = BankAccount::query()
            ->where('company_id', $companyId)
            ->where('ulid', $bankAccount)
            ->with('ledgerAccount')
            ->firstOrFail();

        $this->authorize('view', $account);

        // Page size one: only the closing balance is wanted here, not the lines.
        $ledgerPage = $ledger->generalLedger(
            $companyId,
            $account->ledgerAccount,
            null,
            null,
            1,
            1,
        );

        return response()->json(['data' => [
            ...$this->present($account),
            // The ledger's figure, not the application's: this is the closing
            // balance of the account this bank account is backed by.
            'ledger_balance' => $ledgerPage['meta']['closing_balance'],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankAccount $account): array
    {
        return [
            'id' => (int) $account->getKey(),
            'ulid' => $account->ulid,
            'company_id' => (int) $account->company_id,
            'branch_id' => $account->branch_id === null ? null : (int) $account->branch_id,
            'account_id' => (int) $account->account_id,
            'ledger_account' => [
                'id' => (int) $account->ledgerAccount->getKey(),
                'code' => $account->ledgerAccount->code,
                'name' => $account->ledgerAccount->name,
            ],
            'name' => $account->name,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'currency_code' => $account->currency_code,
            'account_type' => $account->account_type->value,
            'opening_balance' => $account->opening_balance,
            'status' => $account->status->value,
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
