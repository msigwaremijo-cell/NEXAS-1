<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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

        return response()->json([
            'data' => array_map(
                fn (AuditLog $entry): array => $this->present($entry),
                $entries->items()
            ),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AuditLog $entry): array
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
