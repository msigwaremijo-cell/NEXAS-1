<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchases\Actions\ApproveBill;
use App\Domain\Purchases\Actions\CreateBill;
use App\Domain\Purchases\Actions\PostBill;
use App\Domain\Purchases\Actions\SubmitBillForApproval;
use App\Domain\Purchases\Enums\BillStatus;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin HTTP surface over the purchases actions.
 *
 * A bill passes through four gates and each has its own endpoint, because each
 * has its own permission: submit moves the draft behind the approval gate,
 * approve names the member who accepted the liability, post puts it into the
 * ledger. Keeping them apart is what lets the matrix withhold approval from the
 * accountant who entered the bill.
 *
 * Approving does not post, and posting is not a status change: an approved bill
 * stays approved until it is paid, so the presence of a journal is what marks
 * it as posted. The gate itself is enforced in PostBill rather than here, so no
 * other code path can reach the ledger with an unapproved liability.
 */
class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Bill::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(BillStatus::class)],
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $bills = Bill::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['supplier_id']),
                fn ($query) => $query->where('supplier_id', $filters['supplier_id'])
            )
            ->when(
                isset($filters['from']),
                fn ($query) => $query->whereDate('bill_date', '>=', $filters['from'])
            )
            ->when(
                isset($filters['to']),
                fn ($query) => $query->whereDate('bill_date', '<=', $filters['to'])
            )
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Bill $bill): array => $this->present($bill),
                $bills->items()
            ),
            'meta' => [
                'current_page' => $bills->currentPage(),
                'per_page' => $bills->perPage(),
                'total' => $bills->total(),
                'last_page' => $bills->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $bill): JsonResponse
    {
        $record = $this->findByUlid($request, $bill);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record->load('lines'))]);
    }

    public function store(Request $request, CreateBill $create): JsonResponse
    {
        $this->authorize('create', Bill::class);

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'min:1'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'supplier_invoice_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'string', 'max:32'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            // The expense account is what the line's debit posts to, so it is
            // required rather than defaulted.
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.unit_price' => ['required', 'numeric'],
            'lines.*.discount_rate' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.discount_amount' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.tax_code_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $bill = $create(
            $this->companyId($request),
            (int) $data['supplier_id'],
            $data,
            $request->user()->getKey(),
        );

        return response()->json(['data' => $this->present($bill)], 201);
    }

    public function submit(Request $request, string $bill, SubmitBillForApproval $submit): JsonResponse
    {
        $record = $this->findByUlid($request, $bill);

        $this->authorize('submit', $record);

        return response()->json([
            'data' => $this->present($submit($record, $request->user()->getKey())),
        ]);
    }

    public function approve(Request $request, string $bill, ApproveBill $approve): JsonResponse
    {
        $record = $this->findByUlid($request, $bill);

        $this->authorize('approve', $record);

        return response()->json([
            'data' => $this->present($approve($record, $request->user()->getKey())),
        ]);
    }

    public function post(Request $request, string $bill, PostBill $post): JsonResponse
    {
        $record = $this->findByUlid($request, $bill);

        $this->authorize('post', $record);

        $posted = $post(
            $record,
            $request->user()->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->present($posted)]);
    }

    /**
     * Scoped to the caller's company in the same query that resolves the
     * document, so another tenant's bill is a 404 and not an existence oracle.
     */
    private function findByUlid(Request $request, string $ulid): Bill
    {
        return Bill::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Bill $bill): array
    {
        return [
            'id' => (int) $bill->getKey(),
            'ulid' => $bill->ulid,
            'company_id' => (int) $bill->company_id,
            'bill_no' => $bill->bill_no,
            'supplier_id' => (int) $bill->supplier_id,
            'supplier_invoice_no' => $bill->supplier_invoice_no,
            'branch_id' => $bill->branch_id === null ? null : (int) $bill->branch_id,
            'status' => $bill->status->value,
            'approval_status' => $bill->approval_status,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'currency_code' => $bill->currency_code,
            'exchange_rate' => $bill->exchange_rate,
            'reference' => $bill->reference,
            'notes' => $bill->notes,
            'subtotal' => $bill->subtotal,
            'discount_total' => $bill->discount_total,
            'tax_total' => $bill->tax_total,
            'total' => $bill->total,
            'amount_paid' => $bill->amount_paid,
            'outstanding' => $bill->outstanding()->toDecimal(),
            'journal_id' => $bill->journal_id === null ? null : (int) $bill->journal_id,
            'approved_at' => $bill->approved_at?->toIso8601String(),
            'approved_by_user_id' => $bill->approved_by_user_id === null
                ? null
                : (int) $bill->approved_by_user_id,
            'row_version' => (int) $bill->row_version,
            'lines' => $bill->relationLoaded('lines')
                ? array_map(fn (BillLine $line): array => [
                    'id' => (int) $line->getKey(),
                    'line_no' => (int) $line->line_no,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_amount' => $line->discount_amount,
                    'tax_code_id' => $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                    'tax_rate' => $line->tax_rate,
                    'tax_amount' => $line->tax_amount,
                    'line_subtotal' => $line->line_subtotal,
                    'line_total' => $line->line_total,
                    'expense_account_id' => $line->expense_account_id === null
                        ? null
                        : (int) $line->expense_account_id,
                ], $bill->lines->all())
                : [],
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
