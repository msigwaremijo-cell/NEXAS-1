<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\PartyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The customer list, and the single customer read and amended from it.
 *
 * Customers exist so an invoice has a party to name and a receivable control
 * account to roll up into, so this surface is the read half the sales screens
 * need. There is no delete: a customer is master data that documents issued
 * years ago still point at, which is why the model soft-deletes. Amending one
 * is therefore the only way to retire it, by moving its status.
 *
 * `receivable_account_id` is checked against the caller's own company rather
 * than merely existing. Without that, a customer could be pointed at another
 * tenant's account and every invoice raised for them would post into the wrong
 * set of books. The same check is applied on update, because moving an
 * existing customer's control account is exactly as dangerous as setting it.
 */
class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(PartyStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $customers = Customer::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->search($filters['search'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->orderBy('code')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Customer $customer): array => $this->present($customer),
                $customers->items()
            ),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->rules($companyId));

        $customer = new Customer([
            ...$data,
            'company_id' => $companyId,
            'currency_code' => $data['currency_code'] ?? $this->baseCurrencyCode($companyId),
            'status' => $data['status'] ?? PartyStatus::Active->value,
        ]);

        $customer->credit_limit = $data['credit_limit'] ?? '0.0000';

        $customer->save();

        return response()->json(['data' => $this->present($customer)], 201);
    }

    public function show(Request $request, string $customer): JsonResponse
    {
        $record = $this->findByUlid($request, $customer);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * Amending master data. Every rule is `sometimes`, so a caller may send the
     * one field it is changing rather than the whole record, and the code's
     * uniqueness check ignores the row being amended so a customer may keep the
     * code it already has.
     */
    public function update(Request $request, string $customer): JsonResponse
    {
        $record = $this->findByUlid($request, $customer);

        $this->authorize('update', $record);

        $data = $request->validate($this->rules($this->companyId($request), $record));

        $record->fill($data);
        $record->save();

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * The rules a customer is written under, whether it is being created or
     * amended. Creating demands a code, a name and a control account; amending
     * asks for none of them, because a caller may be changing only the status.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(int $companyId, ?Customer $customer = null): array
    {
        $uniqueCode = Rule::unique('customers', 'code')->where('company_id', $companyId);

        if ($customer !== null) {
            $uniqueCode = $uniqueCode->ignore($customer->getKey());
        }

        return [
            'code' => $customer === null
                ? ['required', 'string', 'max:30', $uniqueCode]
                : ['sometimes', 'string', 'max:30', $uniqueCode],
            'name' => $customer === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vrn' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'exists:currencies,code'],
            'receivable_account_id' => $customer === null
                ? [
                    'required', 'integer',
                    Rule::exists('accounts', 'id')->where('company_id', $companyId),
                ]
                : [
                    'sometimes', 'integer',
                    Rule::exists('accounts', 'id')->where('company_id', $companyId),
                ],
            'credit_limit' => ['sometimes', 'string', 'max:32'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', 'string', Rule::enum(PartyStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Scoped to the caller's company in the same query that resolves the
     * record, so another tenant's customer is a 404 rather than an existence
     * oracle. A soft-deleted customer is not found at all.
     */
    private function findByUlid(Request $request, string $ulid): Customer
    {
        return Customer::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $customer): array
    {
        return [
            'id' => (int) $customer->getKey(),
            'ulid' => $customer->ulid,
            'company_id' => (int) $customer->company_id,
            'code' => $customer->code,
            'name' => $customer->name,
            'tin' => $customer->tin,
            'vrn' => $customer->vrn,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => $customer->address,
            'currency_code' => $customer->currency_code,
            'receivable_account_id' => (int) $customer->receivable_account_id,
            'credit_limit' => $customer->credit_limit,
            'payment_terms_days' => (int) $customer->payment_terms_days,
            'status' => $customer->status->value,
        ];
    }

    private function baseCurrencyCode(int $companyId): string
    {
        return (string) Company::query()->findOrFail($companyId)->base_currency_code;
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
