<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Enums\PartyType;
use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Thin HTTP surface over the accounting actions.
 *
 * No rule lives here: the balance check, the period lock, the sequence and the
 * idempotency claim all belong to app/Domain/Accounting/Actions. This class
 * validates the shape of the request, asks the policy whether the caller may
 * do it, calls the action and serialises the answer.
 *
 * The company is never read from the body. It comes from the `company`
 * middleware, which has already proved the caller is a member of it.
 */
class JournalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Journal::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $journals = Journal::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->orderByDesc('posting_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => array_map(
                fn (Journal $journal): array => $this->present($journal),
                $journals->items()
            ),
            'meta' => [
                'current_page' => $journals->currentPage(),
                'per_page' => $journals->perPage(),
                'total' => $journals->total(),
                'last_page' => $journals->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $journal): JsonResponse
    {
        $record = $this->findByUlid($journal);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record->load('lines'))]);
    }

    public function store(Request $request, CreateJournal $create): JsonResponse
    {
        $this->authorize('create', Journal::class);

        $data = $request->validate([
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'document_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'journal_type' => ['sometimes', 'string', Rule::enum(JournalType::class)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'exchange_rate' => ['sometimes', 'string', 'max:32'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.debit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'lines.*.exchange_rate' => ['sometimes', 'nullable', 'string', 'max:32'],
            'lines.*.tax_code_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.party_type' => ['sometimes', 'nullable', 'string', Rule::enum(PartyType::class)],
            'lines.*.party_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lines.*.branch_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $journal = $create(new JournalEntryData(
            companyId: $this->companyId($request),
            postingDate: $data['posting_date'],
            journalType: JournalType::from($data['journal_type'] ?? JournalType::General->value),
            lines: $this->lines($data['lines']),
            reference: $data['reference'] ?? null,
            memo: $data['memo'] ?? null,
            documentDate: $data['document_date'] ?? null,
            currencyCode: $data['currency_code'] ?? null,
            exchangeRate: $data['exchange_rate'] ?? '1',
            createdByUserId: $request->user()->getKey(),
        ));

        return response()->json(['data' => $this->present($journal)], 201);
    }

    public function post(Request $request, string $journal, PostJournal $post): JsonResponse
    {
        $record = $this->findByUlid($journal);

        $this->authorize('post', $record);

        $validated = $request->validate([
            'expected_row_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $posted = $post(
            $record,
            $this->idempotencyKey($request),
            $request->user()->getKey(),
            $validated['expected_row_version'] ?? null,
        );

        return response()->json(['data' => $this->present($posted)]);
    }

    public function reverse(Request $request, string $journal, ReverseJournal $reverse): JsonResponse
    {
        $record = $this->findByUlid($journal);

        $this->authorize('reverse', $record);

        $data = $request->validate([
            'posting_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // The nested PostJournal call is given the same key with a suffix, so
        // retrying the whole reversal replays the reversal rather than trying
        // to post a second one. The unique index on reverses_journal_id is the
        // backstop that refuses a genuine second reversal.
        $key = $this->idempotencyKey($request);

        $reversal = $reverse(
            $record,
            $data['posting_date'] ?? null,
            $data['memo'] ?? null,
            $request->user()->getKey(),
            $key === null ? null : $key.':reversal',
        );

        return response()->json(['data' => $this->present($reversal)], 201);
    }

    /**
     * A draft may be unbalanced and may carry any number of lines: both are
     * posting rules, and this action only writes a draft.
     *
     * Amounts are accepted as decimal strings. A JSON number with a fraction
     * arrives as a PHP float, which cannot round-trip DECIMAL(19,4), so it is
     * refused with a sentence instead of being quietly rounded.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, JournalLineData>
     */
    private function lines(array $lines): array
    {
        $out = [];

        foreach (array_values($lines) as $index => $line) {
            $out[] = new JournalLineData(
                accountId: (int) $line['account_id'],
                debit: $this->decimal($line['debit'] ?? null, "lines.{$index}.debit"),
                credit: $this->decimal($line['credit'] ?? null, "lines.{$index}.credit"),
                description: $line['description'] ?? null,
                currencyCode: $line['currency_code'] ?? null,
                exchangeRate: $line['exchange_rate'] ?? null,
                taxCodeId: isset($line['tax_code_id']) ? (int) $line['tax_code_id'] : null,
                taxAmount: $this->decimal($line['tax_amount'] ?? null, "lines.{$index}.tax_amount"),
                partyType: isset($line['party_type']) ? PartyType::from($line['party_type']) : null,
                partyId: isset($line['party_id']) ? (int) $line['party_id'] : null,
                branchId: isset($line['branch_id']) ? (int) $line['branch_id'] : null,
            );
        }

        return $out;
    }

    private function decimal(mixed $value, string $field): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_float($value)) {
            throw ValidationException::withMessages([
                $field => ['Send money as a decimal string, not a JSON number with a fraction.'],
            ]);
        }

        return (string) $value;
    }

    private function findByUlid(string $ulid): Journal
    {
        return Journal::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Journal $journal): array
    {
        return [
            'id' => $journal->getKey(),
            'ulid' => $journal->ulid,
            'company_id' => (int) $journal->company_id,
            'journal_no' => $journal->journal_no,
            'journal_type' => $journal->journal_type->value,
            'status' => $journal->status->value,
            'posting_date' => $journal->posting_date?->toDateString(),
            'document_date' => $journal->document_date?->toDateString(),
            'reference' => $journal->reference,
            'memo' => $journal->memo,
            'currency_code' => $journal->currency_code,
            'exchange_rate' => $journal->exchange_rate,
            'total_debit' => $journal->total_debit,
            'total_credit' => $journal->total_credit,
            'row_version' => $journal->row_version,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'reverses_journal_id' => $journal->reverses_journal_id,
            'reversed_by_journal_id' => $journal->reversed_by_journal_id,
            'lines' => $journal->relationLoaded('lines')
                ? array_map(fn (JournalLine $line): array => [
                    'id' => $line->getKey(),
                    'line_no' => $line->line_no,
                    'account_id' => (int) $line->account_id,
                    'description' => $line->description,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ], $journal->lines->all())
                : [],
        ];
    }
}
