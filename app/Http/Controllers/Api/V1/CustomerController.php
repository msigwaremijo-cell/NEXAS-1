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
 * The customer list.
 *
 * Customers exist so an invoice has a party to name and a receivable control
 * account to roll up into, so this surface is the read half the sales screens
 * need. There is no delete: a customer is master data that documents issued
 * years ago still point at, which is why the model soft-deletes.
 *
 * `receivable_account_id` is checked against the caller's own company rather
 * than merely existing. Without that, a customer could be pointed at another
 * tenant's account and every invoice raised for them would post into the wrong
 * set of books.
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

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('customers', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vrn' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'exists:currencies,code'],
            'receivable_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'credit_limit' => ['sometimes', 'string', 'max:32'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'status' => ['sometimes', 'string', Rule::enum(PartyStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

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
