<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\PartyStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The supplier list.
 *
 * The mirror of CustomerController, with two differences that come from the
 * schema: a supplier names a payable control account rather than a receivable
 * one, and it carries `withholding_agent`, which marks a supplier the company
 * must withhold tax from when it pays them.
 *
 * The account is checked for membership of the caller's company for the same
 * reason as on the customer side: an account id from another tenant would send
 * every bill's credit line into the wrong books.
 */
class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(PartyStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $suppliers = Supplier::query()
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
                fn (Supplier $supplier): array => $this->present($supplier),
                $suppliers->items()
            ),
            'meta' => [
                'current_page' => $suppliers->currentPage(),
                'per_page' => $suppliers->perPage(),
                'total' => $suppliers->total(),
                'last_page' => $suppliers->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('suppliers', 'code')->where('company_id', $companyId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:20'],
            'vrn' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'exists:currencies,code'],
            'payable_account_id' => [
                'required', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'credit_limit' => ['sometimes', 'string', 'max:32'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'withholding_agent' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::enum(PartyStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $supplier = new Supplier([
            ...$data,
            'company_id' => $companyId,
            'currency_code' => $data['currency_code'] ?? $this->baseCurrencyCode($companyId),
            'status' => $data['status'] ?? PartyStatus::Active->value,
            'withholding_agent' => $data['withholding_agent'] ?? false,
        ]);

        $supplier->credit_limit = $data['credit_limit'] ?? '0.0000';

        $supplier->save();

        return response()->json(['data' => $this->present($supplier)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->getKey(),
            'ulid' => $supplier->ulid,
            'company_id' => (int) $supplier->company_id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'tin' => $supplier->tin,
            'vrn' => $supplier->vrn,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => $supplier->address,
            'currency_code' => $supplier->currency_code,
            'payable_account_id' => (int) $supplier->payable_account_id,
            'credit_limit' => $supplier->credit_limit,
            'payment_terms_days' => (int) $supplier->payment_terms_days,
            'withholding_agent' => (bool) $supplier->withholding_agent,
            'status' => $supplier->status->value,
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
