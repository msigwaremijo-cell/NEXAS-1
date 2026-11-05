<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sales\Actions\CreateCreditNote;
use App\Domain\Sales\Actions\IssueCreditNote;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin HTTP surface over the credit-note actions.
 *
 * No arithmetic and no accounting rule lives here. Pricing, the document
 * number and the posting entry belong to CreateCreditNote and IssueCreditNote,
 * so a credit note raised through this endpoint and one raised by a future
 * importer cannot disagree.
 *
 * A credit note is a row in the invoices table with its own document type, so
 * every lookup is scoped to the caller's company in the same query that
 * resolves the document: another tenant's invoice or credit note is a 404
 * rather than an existence oracle. Writing a draft and issuing it are separate
 * endpoints because they are separate moves; both reuse the invoice rights,
 * because a credit note is the sales side's own correction of a sale.
 */
class CreditNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(InvoiceStatus::class)],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $creditNotes = Invoice::query()
            ->where('company_id', $this->companyId($request))
            ->where('document_type', 'credit_note')
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->when(
                isset($filters['customer_id']),
                fn ($query) => $query->where('customer_id', $filters['customer_id'])
            )
            ->when(
                isset($filters['from']),
                fn ($query) => $query->whereDate('invoice_date', '>=', $filters['from'])
            )
            ->when(
                isset($filters['to']),
                fn ($query) => $query->whereDate('invoice_date', '<=', $filters['to'])
            )
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Invoice $creditNote): array => $this->present($creditNote),
                $creditNotes->items()
            ),
            'meta' => [
                'current_page' => $creditNotes->currentPage(),
                'per_page' => $creditNotes->perPage(),
                'total' => $creditNotes->total(),
                'last_page' => $creditNotes->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, string $invoice, CreateCreditNote $create): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $target = $this->findByUlid($request, $invoice, 'invoice');

        $data = $request->validate([
            'invoice_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            // A credit note line may only name a line of the invoice it
            // corrects and a quantity: the price comes from that line, so a
            // client cannot credit a sale at a figure the company never charged.
            'lines.*.invoice_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric'],
        ]);

        $creditNote = $create(
            $this->companyId($request),
            $target,
            $data,
            $request->user()->getKey(),
        );

        return response()->json(['data' => $this->present($creditNote)], 201);
    }

    public function show(Request $request, string $creditNote): JsonResponse
    {
        $record = $this->findByUlid($request, $creditNote, 'credit_note');

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record->load('lines'))]);
    }

    public function issue(Request $request, string $creditNote, IssueCreditNote $issue): JsonResponse
    {
        $record = $this->findByUlid($request, $creditNote, 'credit_note');

        $this->authorize('issue', $record);

        $issued = $issue(
            $record,
            $request->user()->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->present($issued)]);
    }

    /**
     * Scoped to the caller's company and the expected document type in the same
     * query that resolves the document, so another tenant's document — or one
     * of the wrong kind — is a 404 and not an existence oracle. The policy
     * repeats the membership check as a second lock.
     */
    private function findByUlid(Request $request, string $ulid, string $documentType): Invoice
    {
        return Invoice::query()
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
    private function present(Invoice $creditNote): array
    {
        return [
            'id' => (int) $creditNote->getKey(),
            'ulid' => $creditNote->ulid,
            'company_id' => (int) $creditNote->company_id,
            'invoice_no' => $creditNote->invoice_no,
            'document_type' => $creditNote->document_type,
            'customer_id' => (int) $creditNote->customer_id,
            'branch_id' => $creditNote->branch_id === null ? null : (int) $creditNote->branch_id,
            'credit_note_for_invoice_id' => $creditNote->credit_note_for_invoice_id === null
                ? null
                : (int) $creditNote->credit_note_for_invoice_id,
            'status' => $creditNote->status->value,
            'invoice_date' => $creditNote->invoice_date->toDateString(),
            'due_date' => $creditNote->due_date->toDateString(),
            'currency_code' => $creditNote->currency_code,
            'exchange_rate' => $creditNote->exchange_rate,
            'reference' => $creditNote->reference,
            'notes' => $creditNote->notes,
            'subtotal' => $creditNote->subtotal,
            'discount_total' => $creditNote->discount_total,
            'tax_total' => $creditNote->tax_total,
            'total' => $creditNote->total,
            'amount_paid' => $creditNote->amount_paid,
            'outstanding' => $creditNote->outstanding()->toDecimal(),
            'journal_id' => $creditNote->journal_id === null ? null : (int) $creditNote->journal_id,
            'issued_at' => $creditNote->issued_at?->toIso8601String(),
            'row_version' => (int) $creditNote->row_version,
            'lines' => $creditNote->relationLoaded('lines')
                ? array_map(fn (InvoiceLine $line): array => [
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
                    'revenue_account_id' => $line->revenue_account_id === null
                        ? null
                        : (int) $line->revenue_account_id,
                    'credit_note_for_line_id' => $line->credit_note_for_line_id === null
                        ? null
                        : (int) $line->credit_note_for_line_id,
                ], $creditNote->lines->all())
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
