<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Approvals\Actions\BulkDecideApprovalRequests;
use App\Domain\Approvals\Actions\DecideApprovalRequest;
use App\Domain\Approvals\Actions\SubmitApprovalRequest;
use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Domain\Approvals\Services\ApprovalDetailQuery;
use App\Domain\Approvals\Services\ApprovalQueueQuery;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The approvals queue: one shelf on which every kind of document waits.
 *
 * Three abilities, three sets of endpoints. Reading the line is `approval.view`,
 * raising a document into it is `approval.request` and answering it is
 * `approval.decide`. Keeping them apart is what lets the matrix withhold the
 * decision from the member who raised the document, which is the control the
 * queue exists to be.
 *
 * The no-self-approval rule and the mandatory rejection reason are enforced in
 * the actions rather than here, so a decision reached through any other code
 * path meets them too. What this surface adds is the policy call and the
 * request shape: every decision carries an idempotency key, and every decision
 * writes one link into the audit chain.
 */
class ApprovalRequestController extends Controller
{
    public function index(Request $request, ApprovalQueueQuery $queue): JsonResponse
    {
        $this->authorize('viewAny', ApprovalRequest::class);

        // The whole of what the line may be asked for. The amount, age and
        // date filters are answered in the database rather than in memory, so
        // the page a reader is shown is a page of the set they asked for.
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(ApprovalStatus::class)],
            'document_type' => ['sometimes', 'nullable', Rule::enum(ApprovalDocumentType::class)],
            'preparer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'amount_min' => ['sometimes', 'nullable', 'numeric'],
            'amount_max' => ['sometimes', 'nullable', 'numeric'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'age_min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'age_max' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort' => ['sometimes', 'nullable', Rule::in(['age', 'amount', 'reference', 'type'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($queue->queue($this->companyId($request), $filters));
    }

    public function show(Request $request, string $approvalRequest): JsonResponse
    {
        $record = $this->findByUlid($request, $approvalRequest);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * The whole of one queued document, gathered for the screen that decides it.
     *
     * A read behind the same right as the queue itself: the detail names the
     * document's lines, what it is worth and what is filed beside it, none of
     * which is more than reading the line already shows.
     */
    public function detail(
        Request $request,
        string $approvalRequest,
        ApprovalDetailQuery $detail,
    ): JsonResponse {
        $record = $this->findByUlid($request, $approvalRequest);

        $this->authorize('view', $record);

        return response()->json([
            'data' => $detail->detail($record, $request->user()->getKey()),
        ]);
    }

    /**
     * Decides several queued documents in one answer.
     *
     * The two ceilings — how many documents and how much value — are enforced
     * by the action against the documents the batch really names, so neither
     * can be raised by editing what is sent. A document the server refuses is
     * reported as that item's answer, so one refusal does not discard the rest
     * of the batch.
     */
    public function bulkApprove(Request $request, BulkDecideApprovalRequests $bulk): JsonResponse
    {
        $this->authorize('decideAny', ApprovalRequest::class);

        $data = $request->validate([
            'ulids' => ['required', 'array', 'min:1', 'max:200'],
            'ulids.*' => ['required', 'string', 'size:26'],
        ]);

        $results = $bulk->approve(
            $this->companyId($request),
            array_values($data['ulids']),
            $request->user()->getKey(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $results]);
    }

    public function store(Request $request, SubmitApprovalRequest $submit): JsonResponse
    {
        $this->authorize('create', ApprovalRequest::class);

        $data = $request->validate([
            'document_type' => ['required', Rule::enum(ApprovalDocumentType::class)],
            'document_ulid' => ['required', 'string', 'size:26'],
        ]);

        $approval = $submit(
            $this->companyId($request),
            ApprovalDocumentType::from($data['document_type']),
            $data['document_ulid'],
            $request->user()->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->present($approval)], 201);
    }

    public function approve(
        Request $request,
        string $approvalRequest,
        DecideApprovalRequest $decide,
    ): JsonResponse {
        $record = $this->findByUlid($request, $approvalRequest);

        $this->authorize('decide', $record);

        $decided = $decide->approve(
            $record,
            $request->user()->getKey(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $this->present($decided)]);
    }

    public function reject(
        Request $request,
        string $approvalRequest,
        DecideApprovalRequest $decide,
    ): JsonResponse {
        $record = $this->findByUlid($request, $approvalRequest);

        $this->authorize('decide', $record);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $decided = $decide->reject(
            $record,
            $data['reason'],
            $request->user()->getKey(),
            $this->requireIdempotencyKey($request),
        );

        return response()->json(['data' => $this->present($decided)]);
    }

    /**
     * Scoped to the caller's company in the same query that resolves the row,
     * so another tenant's request is a 404 and not an existence oracle.
     */
    private function findByUlid(Request $request, string $ulid): ApprovalRequest
    {
        return ApprovalRequest::query()
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
     * A decision has to name the attempt it answers.
     *
     * Requiring the key rather than accepting one optionally is what makes
     * "every decision is idempotent" true of the surface rather than true of
     * the clients that choose to honour it.
     */
    private function requireIdempotencyKey(Request $request): string
    {
        return $this->idempotencyKey($request) ?? throw ApprovalsException::idempotencyKeyRequired();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ApprovalRequest $approval): array
    {
        return [
            'id' => (int) $approval->getKey(),
            'ulid' => $approval->ulid,
            'company_id' => (int) $approval->company_id,
            'document_type' => $approval->document_type->value,
            'document_type_label' => $approval->document_type->label(),
            'document_id' => (int) $approval->document_id,
            'document_ulid' => $approval->document_ulid,
            'document_reference' => $approval->document_reference,
            'status' => $approval->status->value,
            'requested_by_user_id' => $approval->requested_by_user_id === null
                ? null
                : (int) $approval->requested_by_user_id,
            'decided_by_user_id' => $approval->decided_by_user_id === null
                ? null
                : (int) $approval->decided_by_user_id,
            'decision_reason' => $approval->decision_reason,
            'decided_at' => $approval->decided_at?->toIso8601String(),
            'row_version' => (int) $approval->row_version,
            'created_at' => $approval->created_at?->toIso8601String(),
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
