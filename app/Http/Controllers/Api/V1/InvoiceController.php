<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sales\Actions\CreateInvoice;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Thin HTTP surface over the sales actions.
 *
 * No arithmetic and no accounting rule lives here. Pricing, the due date, the
 * document number and the posting entry belong to CreateInvoice and
 * IssueInvoice, so a draft raised through this endpoint and one raised by a
 * future importer or console command cannot disagree.
 *
 * Writing a draft and issuing it are separate endpoints because they are
 * separate permissions: preparing an invoice is clerical, issuing it changes
 * the ledger. The totals are never read from the body — they are absent from
 * the model's fillable list — so a client cannot assert its own invoice total.
 */
class InvoiceController extends Controller
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

        $invoices = Invoice::query()
            ->where('company_id', $this->companyId($request))
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
                fn (Invoice $invoice): array => $this->present($invoice),
                $invoices->items()
            ),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'last_page' => $invoices->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $invoice): JsonResponse
    {
        $record = $this->findByUlid($request, $invoice);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record->load('lines'))]);
    }

    public function store(Request $request, CreateInvoice $create): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'string', 'max:32'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            // A product names the item the line is for. Naming a stock item is
            // what makes issuing the invoice take the goods off the shelf, so
            // the id is validated even though the line itself does not require
            // one: a service line is sold without a movement.
            'lines.*.product_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            // The revenue account is what the line's credit posts to, so it is
            // required here rather than defaulted: guessing it would silently
            // post revenue to the wrong account.
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric'],
            'lines.*.unit_price' => ['required', 'numeric'],
            'lines.*.discount_rate' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.discount_amount' => ['sometimes', 'nullable', 'numeric'],
            'lines.*.tax_code_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $invoice = $create(
            $this->companyId($request),
            (int) $data['customer_id'],
            $data,
            $request->user()->getKey(),
        );

        return response()->json(['data' => $this->present($invoice)], 201);
    }

    public function issue(Request $request, string $invoice, IssueInvoice $issue): JsonResponse
    {
        $record = $this->findByUlid($request, $invoice);

        $this->authorize('issue', $record);

        $issued = $issue(
            $record,
            $request->user()->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->present($issued)]);
    }

    /**
     * Scoped to the caller's company in the same query that resolves the
     * document, so another tenant's invoice is a 404 and not an existence
     * oracle. The policy repeats the membership check as a second lock.
     */
    private function findByUlid(Request $request, string $ulid): Invoice
    {
        return Invoice::query()
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
    private function present(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->getKey(),
            'ulid' => $invoice->ulid,
            'company_id' => (int) $invoice->company_id,
            'invoice_no' => $invoice->invoice_no,
            'customer_id' => (int) $invoice->customer_id,
            'branch_id' => $invoice->branch_id === null ? null : (int) $invoice->branch_id,
            'status' => $invoice->status->value,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'currency_code' => $invoice->currency_code,
            'exchange_rate' => $invoice->exchange_rate,
            'reference' => $invoice->reference,
            'notes' => $invoice->notes,
            'subtotal' => $invoice->subtotal,
            'discount_total' => $invoice->discount_total,
            'tax_total' => $invoice->tax_total,
            'total' => $invoice->total,
            'amount_paid' => $invoice->amount_paid,
            'outstanding' => $invoice->outstanding()->toDecimal(),
            'journal_id' => $invoice->journal_id === null ? null : (int) $invoice->journal_id,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'row_version' => (int) $invoice->row_version,
            'lines' => $invoice->relationLoaded('lines')
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
                ], $invoice->lines->all())
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
