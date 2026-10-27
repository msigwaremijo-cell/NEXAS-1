<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Banking\Actions\RecordPayment;
use App\Domain\Banking\DTOs\PaymentData;
use App\Domain\Banking\Enums\PaymentDirection;
use App\Domain\Banking\Enums\PaymentMethod;
use App\Domain\Banking\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Receipts from customers and payments to suppliers.
 *
 * The whole of the accounting lives in RecordPayment: which control account the
 * money moves against, how the journal is built, how an allocation updates the
 * document it settles down to, and how a retried receipt is refused by the
 * unique idempotency index rather than credited twice.
 *
 * What this class does do is translate the wire format into the domain's. Two
 * translations are worth naming:
 *
 *   - A document is named by its public ULID, as everywhere else on this
 *     surface, and resolved to the internal key here. The action takes keys.
 *
 *   - `party_type` is derived from `direction` when the client omits it, since
 *     a receipt can only be from a customer and a cash payment can only be to a
 *     supplier. The action still refuses a mismatch, so deriving it here is a
 *     convenience and not the guard.
 *
 * Money is accepted as a decimal string, never as a JSON number with a
 * fraction: a float cannot round-trip DECIMAL(19,4), so it is refused with a
 * sentence rather than quietly rounded.
 */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $filters = $request->validate([
            'direction' => ['sometimes', 'nullable', Rule::enum(PaymentDirection::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(PaymentStatus::class)],
            'party_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['direction'] ?? null),
                fn ($query) => $query->where('direction', $filters['direction'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['party_id']),
                fn ($query) => $query->where('party_id', $filters['party_id'])
            )
            ->when(
                isset($filters['from']),
                fn ($query) => $query->whereDate('payment_date', '>=', $filters['from'])
            )
            ->when(
                isset($filters['to']),
                fn ($query) => $query->whereDate('payment_date', '<=', $filters['to'])
            )
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Payment $payment): array => $this->present($payment),
                $payments->items()
            ),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        $record = $this->findByUlid($request, $payment);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record->load('allocations'))]);
    }

    public function store(Request $request, RecordPayment $record): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'direction' => ['required', 'string', Rule::enum(PaymentDirection::class)],
            'party_type' => ['sometimes', 'nullable', 'string', Rule::enum(PartyType::class)],
            'party_id' => ['required', 'integer', 'min:1'],
            'bank_account_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId),
            ],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
            'method' => ['sometimes', 'string', Rule::enum(PaymentMethod::class)],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'string', 'max:32'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.document_type' => ['required_with:allocations', 'string', Rule::in(['invoice', 'bill'])],
            'allocations.*.document_id' => ['required_with:allocations', 'string', 'max:64'],
            'allocations.*.amount' => ['required_with:allocations', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
        ]);

        $direction = PaymentDirection::from($data['direction']);

        $payment = $record(new PaymentData(
            companyId: $companyId,
            direction: $direction,
            partyType: isset($data['party_type'])
                ? PartyType::from($data['party_type'])
                : ($direction->isInflow() ? PartyType::Customer : PartyType::Supplier),
            partyId: (int) $data['party_id'],
            bankAccountId: (int) $data['bank_account_id'],
            paymentDate: $data['payment_date'],
            amount: $data['amount'],
            method: PaymentMethod::from($data['method'] ?? PaymentMethod::Bank->value),
            allocations: $this->allocations($companyId, $data['allocations'] ?? []),
            currencyCode: $data['currency_code'] ?? null,
            exchangeRate: $data['exchange_rate'] ?? '1',
            reference: $data['reference'] ?? null,
            userId: $request->user()->getKey(),
            idempotencyKey: $this->idempotencyKey($request),
        ));

        return response()->json(['data' => $this->present($payment)], 201);
    }

    /**
     * Turns the public ULIDs the caller sent into the internal keys the action
     * works with, scoped to the caller's own company so a document from another
     * tenant resolves to nothing rather than to somebody else's invoice.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, array{document_type: string, document_id: int, amount: string}>
     */
    private function allocations(int $companyId, array $allocations): array
    {
        $resolved = [];

        foreach (array_values($allocations) as $index => $allocation) {
            $type = (string) $allocation['document_type'];
            $ulid = (string) $allocation['document_id'];

            $documentId = $type === 'invoice'
                ? Invoice::query()->where('company_id', $companyId)->where('ulid', $ulid)->value('id')
                : Bill::query()->where('company_id', $companyId)->where('ulid', $ulid)->value('id');

            if ($documentId === null) {
                throw ValidationException::withMessages([
                    "allocations.{$index}.document_id" => [
                        "No {$type} with that reference belongs to this company.",
                    ],
                ]);
            }

            $resolved[] = [
                'document_type' => $type,
                'document_id' => (int) $documentId,
                'amount' => (string) $allocation['amount'],
            ];
        }

        return $resolved;
    }

    private function findByUlid(Request $request, string $ulid): Payment
    {
        return Payment::query()
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
    private function present(Payment $payment): array
    {
        return [
            'id' => (int) $payment->getKey(),
            'ulid' => $payment->ulid,
            'company_id' => (int) $payment->company_id,
            'payment_no' => $payment->payment_no,
            'direction' => $payment->direction->value,
            'party_type' => $payment->party_type->value,
            'party_id' => (int) $payment->party_id,
            'bank_account_id' => $payment->bank_account_id === null
                ? null
                : (int) $payment->bank_account_id,
            'payment_date' => $payment->payment_date?->toDateString(),
            'currency_code' => $payment->currency_code,
            'exchange_rate' => $payment->exchange_rate,
            'amount' => $payment->amount,
            'allocated_amount' => $payment->allocated_amount,
            'unapplied_amount' => $payment->unapplied_amount,
            'method' => $payment->method->value,
            'reference' => $payment->reference,
            'status' => $payment->status->value,
            'journal_id' => $payment->journal_id === null ? null : (int) $payment->journal_id,
            'posted_at' => $payment->posted_at?->toIso8601String(),
            'row_version' => (int) $payment->row_version,
            'allocations' => $payment->relationLoaded('allocations')
                ? array_map(fn (PaymentAllocation $allocation): array => [
                    'id' => (int) $allocation->getKey(),
                    'document_type' => $allocation->document_type,
                    'document_id' => (int) $allocation->document_id,
                    'amount' => $allocation->amount,
                    'journal_line_id' => $allocation->journal_line_id === null
                        ? null
                        : (int) $allocation->journal_line_id,
                ], $payment->allocations->all())
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
