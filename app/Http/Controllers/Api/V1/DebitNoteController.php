<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Purchases\Actions\CreateDebitNote;
use App\Domain\Purchases\Actions\PostDebitNote;
use App\Domain\Purchases\Enums\BillStatus;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\BillLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin HTTP surface over the debit-note actions.
 *
 * No arithmetic and no accounting rule lives here. Pricing, the document number
 * and the posting entry belong to CreateDebitNote and PostDebitNote, so a debit
 * note raised through this endpoint and one raised by a future importer cannot
 * disagree.
 *
 * A debit note is a row in the bills table with its own document type, so every
 * lookup is scoped to the caller's company *and* the expected document type in
 * the same query that resolves the document: another tenant's bill, or one of
 * the wrong kind, is a 404 rather than an existence oracle. That second filter
 * matters more here than on the sales side, because a bill and a debit note
 * share one table — without it, the bill route and the debit-note route would
 * each be able to reach the other's documents.
 *
 * Writing a draft and posting it are separate endpoints because they are
 * separate moves; both reuse the bill rights, because a debit note is the
 * purchase side's own correction of a purchase. There is deliberately no update
 * or delete route: a posted note is immutable and a mistake is corrected by
 * reversing it, which is a later move rather than an edit.
 */
class DebitNoteController extends Controller
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

        $debitNotes = Bill::query()
            ->where('company_id', $this->companyId($request))
            ->where('document_type', 'debit_note')
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
                fn (Bill $debitNote): array => $this->present($debitNote),
                $debitNotes->items()
            ),
            'meta' => [
                'current_page' => $debitNotes->currentPage(),
                'per_page' => $debitNotes->perPage(),
                'total' => $debitNotes->total(),
                'last_page' => $debitNotes->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, string $bill, CreateDebitNote $create): JsonResponse
    {
        $this->authorize('create', Bill::class);

        $target = $this->findByUlid($request, $bill, 'bill');

        $data = $request->validate([
            'bill_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            // A debit note line may only name a line of the bill it corrects
            // and a quantity: the price comes from that line, so a client
            // cannot debit a purchase at a figure the supplier never charged.
            'lines.*.bill_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric'],
        ]);

        $debitNote = $create(
            $this->companyId($request),
            $target,
            $data,
            $request->user()->getKey(),
        );

        return response()->json(['data' => $this->present($debitNote)], 201);
    }

    public function show(Request $request, string $debitNote): JsonResponse
    {
        $record = $this->findByUlid($request, $debitNote, 'debit_note');

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record->load('lines'))]);
    }

    public function post(Request $request, string $debitNote, PostDebitNote $post): JsonResponse
    {
        $record = $this->findByUlid($request, $debitNote, 'debit_note');

        $this->authorize('post', $record);

        $posted = $post(
            $record,
            $request->user()->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->present($posted)]);
    }

    /**
     * Scoped to the caller's company and the expected document type in the same
     * query that resolves the document, so another tenant's document — or one
     * of the wrong kind, sharing this table — is a 404 and not an existence
     * oracle. The policy repeats the membership check as a second lock.
     */
    private function findByUlid(Request $request, string $ulid, string $documentType): Bill
    {
        return Bill::query()
            ->where('company_id', $this->companyId($request))
            ->where('document_type', $documentType)
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
    private function present(Bill $debitNote): array
    {
        return [
            'id' => (int) $debitNote->getKey(),
            'ulid' => $debitNote->ulid,
            'company_id' => (int) $debitNote->company_id,
            'bill_no' => $debitNote->bill_no,
            'document_type' => $debitNote->document_type,
            'supplier_id' => (int) $debitNote->supplier_id,
            'branch_id' => $debitNote->branch_id === null ? null : (int) $debitNote->branch_id,
            'debit_note_for_bill_id' => $debitNote->debit_note_for_bill_id === null
                ? null
                : (int) $debitNote->debit_note_for_bill_id,
            'status' => $debitNote->status->value,
            'bill_date' => $debitNote->bill_date?->toDateString(),
            'due_date' => $debitNote->due_date?->toDateString(),
            'currency_code' => $debitNote->currency_code,
            'exchange_rate' => $debitNote->exchange_rate,
            'reference' => $debitNote->reference,
            'notes' => $debitNote->notes,
            'subtotal' => $debitNote->subtotal,
            'discount_total' => $debitNote->discount_total,
            'tax_total' => $debitNote->tax_total,
            'total' => $debitNote->total,
            'amount_paid' => $debitNote->amount_paid,
            'outstanding' => $debitNote->outstanding()->toDecimal(),
            'journal_id' => $debitNote->journal_id === null ? null : (int) $debitNote->journal_id,
            'row_version' => (int) $debitNote->row_version,
            'lines' => $debitNote->relationLoaded('lines')
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
                    'debit_note_for_line_id' => $line->debit_note_for_line_id === null
                        ? null
                        : (int) $line->debit_note_for_line_id,
                ], $debitNote->lines->all())
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
