<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\AuditChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reading the audit chain.
 *
 * A read-only surface and deliberately so: the table is append-only and a
 * trigger rejects UPDATE and DELETE outright, so there is no write ability to
 * offer even for the Owner. What this screen answers is who changed what and
 * what it held before, which is the evidence behind every other number.
 *
 * `audit.view` is its own right — the Owner and the Auditor hold it — so a
 * member trusted to read the books is not automatically trusted to read the
 * trail. The list is scoped to the caller's company, because the chain is
 * written per company and naming another company's entries would be an
 * existence oracle.
 *
 * The page is also checked against the hash chain as it is read, so what is
 * shown is shown as verified or broken rather than merely listed. That check
 * is deliberately page-scoped and cheap: `php artisan audit:verify` is the
 * authoritative check of the whole chain, and a page cannot pretend to have
 * run it.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
            // The subject's class basename, e.g. "Bill", rather than the fully
            // qualified name, because that is what a caller can reasonably know.
            'subject' => ['sometimes', 'nullable', 'string', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = AuditLog::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['action'] ?? null),
                fn ($query) => $query->where('action', $filters['action'])
            )
            ->when(
                filled($filters['subject'] ?? null),
                fn ($query) => $query->where('auditable_type', 'like', '%\\'.$filters['subject'])
            )
            ->when(
                filled($filters['user_id'] ?? null),
                fn ($query) => $query->where('user_id', $filters['user_id'])
            )
            ->with('user')
            // Newest first: an audit trail is read backwards from what just
            // happened. created_at is unique per link in practice because the
            // chain is written under a lock, so id is a stable tiebreaker.
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $rows = $entries->items();
        $chain = $this->verifyChain($rows);

        return response()->json([
            'data' => array_map(
                fn (AuditLog $entry): array => $this->present($entry, $chain['valid']),
                $rows
            ),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
                'chain' => $chain['summary'],
            ],
        ]);
    }

    /**
     * Check the rows on this page against the hash chain.
     *
     * Two questions are asked of each row: does its stored hash recompute from
     * its own content, and does its previous_hash name the row before it. The
     * link into the page's oldest row is off-page — the row it names is not
     * loaded — so it is left unchecked rather than assumed, which is why the
     * summary reports what it did check rather than a bare "intact".
     *
     * @param  list<AuditLog>  $entries  the page, newest first
     * @return array{valid: array<int, bool>, summary: array<string, mixed>}
     */
    private function verifyChain(array $entries): array
    {
        $valid = [];
        $broken = [];

        // Oldest first, so a row can be held against the one written before it.
        $ascending = array_reverse($entries);

        $expectedPrevious = null;
        $linkKnown = false;
        $linksChecked = 0;
        $linksIntact = true;

        foreach ($ascending as $entry) {
            $row = $this->chainRow($entry);
            $id = (int) $entry->getKey();

            $contentOk = hash_equals(AuditChain::hash($row), (string) $row->hash);
            $valid[$id] = $contentOk;

            if (! $contentOk) {
                $broken[] = $id;
            }

            if ($linkKnown && (string) ($row->previous_hash ?? '') !== (string) $expectedPrevious) {
                $linksIntact = false;
                $broken[] = $id;
            }

            if ($linkKnown) {
                $linksChecked++;
            }

            $expectedPrevious = $row->hash;
            $linkKnown = true;
        }

        return [
            'valid' => $valid,
            'summary' => [
                'scope' => 'page',
                'checked' => count($ascending),
                'links_checked' => $linksChecked,
                'intact' => $broken === [],
                'broken_ids' => array_values(array_unique($broken)),
            ],
        ];
    }

    /**
     * The row as the hashing trigger saw it: the raw stored text of the JSON
     * columns, not the arrays Eloquent casts them to.
     */
    private function chainRow(AuditLog $entry): object
    {
        return (object) [
            'company_id' => $entry->company_id,
            'user_id' => $entry->user_id,
            'action' => $entry->action,
            'auditable_type' => $entry->auditable_type,
            'auditable_id' => $entry->auditable_id,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'before' => $entry->getRawOriginal('before'),
            'after' => $entry->getRawOriginal('after'),
            'meta' => $entry->getRawOriginal('meta'),
            'previous_hash' => $entry->previous_hash,
            'hash' => $entry->hash,
        ];
    }

    /**
     * @param  array<int, bool>  $valid
     * @return array<string, mixed>
     */
    private function present(AuditLog $entry, array $valid = []): array
    {
        return [
            'id' => (int) $entry->getKey(),
            'ulid' => $entry->ulid,
            'action' => $entry->action,
            'subject_type' => class_basename((string) $entry->auditable_type),
            'subject_id' => $entry->auditable_id === null ? null : (int) $entry->auditable_id,
            'user' => $entry->user === null ? null : [
                'id' => (int) $entry->user->getKey(),
                'name' => $entry->user->name,
            ],
            'before' => $entry->before,
            'after' => $entry->after,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at?->toIso8601String(),
            'chain_valid' => $valid[(int) $entry->getKey()] ?? null,
        ];
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
