<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The approvals line, read for a screen rather than for one decision.
 *
 * The queue row itself carries only the handle of the document it names, so
 * everything a reader wants beside it — what the document is worth, who it is
 * with — lives in the document's own table. Rather than resolving each row one
 * at a time, the five tables the queue can name are read once into a single
 * derived table keyed by document type and ULID, and the queue is joined to it.
 * That is what lets the line be filtered by amount and searched by party name
 * in the database, so pagination stays honest: a page of the filtered set is a
 * page of the set the reader asked for, not a page of the whole queue thinned
 * in memory.
 *
 * Nothing here is a financial figure. The amount beside a queue row is the
 * document's own total, read so a reader can recognise what they are deciding;
 * it is not a ledger balance and no sum of it is posted anywhere.
 */
final class ApprovalQueueQuery
{
    /**
     * The orderings the line may be asked for, mapped to what they sort on.
     *
     * A whitelist rather than a passthrough, because the value arrives from a
     * query string and lands in a SQL ORDER BY. `age` is the default and reads
     * oldest first — the queue is worked from the front.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'age' => 'ar.created_at',
        'amount' => 'doc.amount',
        'reference' => 'ar.document_reference',
        'type' => 'ar.document_type',
    ];

    /**
     * One page of the line, and the summary the header reads.
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function queue(int $companyId, array $filters): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        $page = $this->query($companyId, $filters)
            ->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        return [
            'data' => array_map(
                fn (object $row): array => $this->present($row),
                array_values($page->items()),
            ),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'summary' => $this->summary($companyId),
                'preparers' => $this->preparers($companyId),
                'document_types' => array_map(
                    fn (ApprovalDocumentType $type): array => [
                        'value' => $type->value,
                        'label' => $type->label(),
                    ],
                    ApprovalDocumentType::cases(),
                ),
                'sortable' => array_keys(self::SORTS),
                // The two ceilings the screen works under, read from config
                // rather than guessed at, so the warning beside a large
                // document states the figure the server enforces.
                'config' => [
                    'normal_threshold' => (float) config('nexas.approvals.normal_threshold'),
                    'bulk_limit' => (float) config('nexas.approvals.bulk_limit'),
                    'bulk_max_items' => (int) config('nexas.approvals.bulk_max_items'),
                ],
            ],
        ];
    }

    /**
     * The queue rows named by a set of ULIDs, keyed by ULID.
     *
     * Read through the same query the line is, so a document's value is
     * measured the same way whether it is shown in the queue or checked
     * against the bulk ceiling. A ULID belonging to another company simply
     * does not appear, so a caller cannot reach a document by naming it.
     *
     * @param  list<string>  $ulids
     * @return array<string, array<string, mixed>>
     */
    public function lines(int $companyId, array $ulids): array
    {
        if ($ulids === []) {
            return [];
        }

        $rows = $this->query($companyId, [])
            ->whereIn('ar.ulid', $ulids)
            ->get();

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row->ulid] = $this->present($row);
        }

        return $indexed;
    }

    /**
     * The four numbers the header states: what is waiting, what it is worth,
     * how long the oldest has waited, and when this answer was read.
     *
     * Read over the whole company rather than over the page, because a header
     * that changed as a reader turned a page would be a header about the page.
     *
     * @return array<string, mixed>
     */
    public function summary(int $companyId): array
    {
        $row = DB::table('approval_requests as ar')
            ->where('ar.company_id', $companyId)
            ->where('ar.status', ApprovalStatus::Pending->value)
            ->leftJoinSub(
                $this->documentIndex($companyId),
                'doc',
                fn (JoinClause $join): JoinClause => $this->onDocument($join),
            )
            ->selectRaw('count(*) as pending_count')
            ->selectRaw('coalesce(sum(doc.amount), 0) as pending_value')
            ->selectRaw('min(ar.created_at) as oldest_created_at')
            ->first();

        $oldest = $row === null || $row->oldest_created_at === null
            ? null
            : CarbonImmutable::parse((string) $row->oldest_created_at);

        return [
            'pending_count' => $row === null ? 0 : (int) $row->pending_count,
            'pending_value' => $row === null ? '0' : (string) $row->pending_value,
            'oldest_pending_days' => $oldest === null
                ? null
                : max(0, (int) $oldest->diffInDays(CarbonImmutable::now())),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * The members who have raised something into the queue, so the preparer
     * filter offers the people who are actually in the line rather than the
     * whole company.
     *
     * @return list<array{id: int, name: string}>
     */
    public function preparers(int $companyId): array
    {
        return DB::table('approval_requests as ar')
            ->join('users', 'users.id', '=', 'ar.requested_by_user_id')
            ->where('ar.company_id', $companyId)
            ->whereNotNull('ar.requested_by_user_id')
            ->distinct()
            ->orderBy('users.name')
            ->get(['users.id as id', 'users.name as name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(int $companyId, array $filters): Builder
    {
        $query = DB::table('approval_requests as ar')
            ->where('ar.company_id', $companyId)
            ->leftJoinSub(
                $this->documentIndex($companyId),
                'doc',
                fn (JoinClause $join): JoinClause => $this->onDocument($join),
            )
            ->leftJoin('users as preparer', 'preparer.id', '=', 'ar.requested_by_user_id')
            ->select([
                'ar.*',
                'doc.amount as document_amount',
                'doc.party_name as party_name',
                'preparer.name as preparer_name',
            ]);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters);

        return $query->orderBy('ar.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (($status = $this->string($filters, 'status')) !== null) {
            $query->where('ar.status', $status);
        }

        if (($type = $this->string($filters, 'document_type')) !== null) {
            $query->where('ar.document_type', $type);
        }

        if (($preparer = $this->integer($filters, 'preparer_id')) !== null) {
            $query->where('ar.requested_by_user_id', $preparer);
        }

        if (($min = $this->number($filters, 'amount_min')) !== null) {
            $query->where('doc.amount', '>=', $min);
        }

        if (($max = $this->number($filters, 'amount_max')) !== null) {
            $query->where('doc.amount', '<=', $max);
        }

        if (($from = $this->string($filters, 'date_from')) !== null) {
            $query->where('ar.created_at', '>=', $from.' 00:00:00');
        }

        if (($to = $this->string($filters, 'date_to')) !== null) {
            $query->where('ar.created_at', '<=', $to.' 23:59:59');
        }

        // Age is measured on the moment the request was raised, which is when
        // the queue started holding it for a decision.
        if (($ageMin = $this->integer($filters, 'age_min')) !== null) {
            $query->where('ar.created_at', '<=', CarbonImmutable::now()->subDays($ageMin));
        }

        if (($ageMax = $this->integer($filters, 'age_max')) !== null) {
            $query->where('ar.created_at', '>=', CarbonImmutable::now()->subDays($ageMax));
        }

        if (($search = $this->string($filters, 'search')) !== null) {
            $query->where(function (Builder $inner) use ($search): void {
                $like = '%'.$search.'%';
                $inner->where('ar.document_reference', 'like', $like)
                    ->orWhere('doc.party_name', 'like', $like);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $sort = self::SORTS[(string) ($filters['sort'] ?? 'age')] ?? self::SORTS['age'];

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);
    }

    /**
     * The document tables the queue can name, keyed by the same type label the
     * queue row uses, so a row joins to its own document and to no other.
     *
     * Bills carry three of the six types in one table, so the row's own
     * `document_type` column is what tells a supplier bill from a credit or a
     * debit note; the other three tables are one type each.
     */
    private function documentIndex(int $companyId): Builder
    {
        $journals = DB::table('journals')
            ->where('journals.company_id', $companyId)
            ->selectRaw("'journal' as doc_type")
            ->selectRaw('journals.ulid as doc_ulid')
            // A journal in the queue is a draft, and a draft's totals are only
            // stamped when it is posted — so the figure a reader is shown has
            // to be summed from the lines themselves rather than read off a
            // column that is still zero.
            ->selectRaw(
                '(select coalesce(sum(jl.debit), 0) from journal_lines jl where jl.journal_id = journals.id) as amount'
            )
            ->selectRaw('cast(null as char(255)) as party_name');

        $bills = DB::table('bills')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'bills.supplier_id')
            ->where('bills.company_id', $companyId)
            ->selectRaw('bills.document_type as doc_type')
            ->selectRaw('bills.ulid as doc_ulid')
            ->selectRaw('bills.total as amount')
            ->selectRaw('suppliers.name as party_name');

        $payments = DB::table('payments')
            ->leftJoin('customers', function (JoinClause $join): void {
                $join->on('customers.id', '=', 'payments.party_id')
                    ->where('payments.party_type', '=', 'customer');
            })
            ->leftJoin('suppliers as payment_supplier', function (JoinClause $join): void {
                $join->on('payment_supplier.id', '=', 'payments.party_id')
                    ->where('payments.party_type', '=', 'supplier');
            })
            ->where('payments.company_id', $companyId)
            ->selectRaw("'payment' as doc_type")
            ->selectRaw('payments.ulid as doc_ulid')
            ->selectRaw('payments.amount as amount')
            ->selectRaw('coalesce(customers.name, payment_supplier.name) as party_name');

        $assets = DB::table('assets')
            ->where('assets.company_id', $companyId)
            ->selectRaw("'asset_disposal' as doc_type")
            ->selectRaw('assets.ulid as doc_ulid')
            ->selectRaw('assets.disposal_proceeds as amount')
            ->selectRaw('cast(null as char(255)) as party_name');

        // A plan's single figure is its planned net for the year: revenue less
        // expense, which is the number a reader weighs a plan by. It is
        // deliberately read the same way the plan itself is presented — the
        // lines are signed on their own side — so the figure the queue shows
        // and the figure the plan screen shows cannot disagree.
        $budgets = DB::table('budget_versions')
            ->where('budget_versions.company_id', $companyId)
            ->selectRaw("'budget' as doc_type")
            ->selectRaw('budget_versions.ulid as doc_ulid')
            ->selectRaw(
                '(select coalesce(sum(case when a.account_type = ? then bvl.amount else -bvl.amount end), 0) '
                .'from budget_version_lines bvl join accounts a on a.id = bvl.account_id '
                .'where bvl.budget_version_id = budget_versions.id) as amount',
                [AccountType::Revenue->value]
            )
            ->selectRaw('cast(null as char(255)) as party_name');

        return $journals
            ->unionAll($bills)
            ->unionAll($payments)
            ->unionAll($assets)
            ->unionAll($budgets);
    }

    private function onDocument(JoinClause $join): JoinClause
    {
        return $join
            ->on('doc.doc_ulid', '=', 'ar.document_ulid')
            ->on('doc.doc_type', '=', 'ar.document_type');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        $status = ApprovalStatus::tryFrom((string) $row->status);
        $type = ApprovalDocumentType::tryFrom((string) $row->document_type);
        $created = $row->created_at === null ? null : CarbonImmutable::parse((string) $row->created_at);

        return [
            'id' => (int) $row->id,
            'ulid' => (string) $row->ulid,
            'document_type' => (string) $row->document_type,
            'document_type_label' => $type?->label() ?? (string) $row->document_type,
            'document_reference' => $row->document_reference === null ? null : (string) $row->document_reference,
            'party_name' => $row->party_name === null ? null : (string) $row->party_name,
            'preparer_id' => $row->requested_by_user_id === null ? null : (int) $row->requested_by_user_id,
            'preparer_name' => $row->preparer_name === null ? null : (string) $row->preparer_name,
            'amount' => $row->document_amount === null ? null : (string) $row->document_amount,
            'status' => (string) $row->status,
            'status_label' => $status?->label() ?? (string) $row->status,
            'age_days' => $created === null
                ? null
                : max(0, (int) $created->diffInDays(CarbonImmutable::now())),
            'decision_reason' => $row->decision_reason === null ? null : (string) $row->decision_reason,
            'decided_at' => $row->decided_at === null ? null : CarbonImmutable::parse((string) $row->decided_at)->toIso8601String(),
            'row_version' => (int) $row->row_version,
            'created_at' => $created?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function string(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function integer(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function number(array $filters, string $key): ?float
    {
        $value = $filters[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
