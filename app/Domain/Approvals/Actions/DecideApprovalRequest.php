<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Domain\Approvals\Exceptions\ApprovalStateException;
use App\Domain\Reporting\Actions\BudgetVersionLifecycle;
use App\Models\ApprovalRequest;
use App\Models\IdempotencyKey;
use App\Support\Enums\IdempotencyStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Decides a document that is waiting in the approvals queue.
 *
 * Two refusals are enforced here rather than left to the caller's discretion,
 * and both are global rather than grants, so adding a role can never quietly
 * remove them:
 *
 *   - a member may not decide a request they themselves raised, which is what
 *     makes the queue a second pair of hands rather than a second signature;
 *   - a decision is final, so a request that already carries one is refused
 *     rather than re-decided.
 *
 * Every decision names the attempt it answers with an idempotency key, so a
 * retried approval replays the first answer instead of deciding the document
 * twice, and writes one link into the audit chain, so the queue and the trail
 * say the same thing.
 */
final class DecideApprovalRequest
{
    private const SCOPE = 'approval.decide';

    public function __construct(private readonly AuditTrail $audit) {}

    public function approve(
        ApprovalRequest $request,
        ?int $decidedByUserId,
        string $idempotencyKey,
    ): ApprovalRequest {
        return $this->decide($request, ApprovalStatus::Approved, null, $decidedByUserId, $idempotencyKey);
    }

    /**
     * A rejection has to say why: the reason is the only thing the member who
     * raised the document is given to act on, so a bare "rejected" is refused
     * before any row is touched.
     */
    public function reject(
        ApprovalRequest $request,
        string $reason,
        ?int $decidedByUserId,
        string $idempotencyKey,
    ): ApprovalRequest {
        $reason = trim($reason);

        if ($reason === '') {
            throw ApprovalsException::reasonRequired();
        }

        return $this->decide($request, ApprovalStatus::Rejected, $reason, $decidedByUserId, $idempotencyKey);
    }

    private function decide(
        ApprovalRequest $request,
        ApprovalStatus $decision,
        ?string $reason,
        ?int $decidedByUserId,
        string $idempotencyKey,
    ): ApprovalRequest {
        return DB::transaction(function () use (
            $request,
            $decision,
            $reason,
            $decidedByUserId,
            $idempotencyKey
        ): ApprovalRequest {
            $record = $this->claimKey($request, $decision, $idempotencyKey);

            if ($record->status->isReplayable()) {
                return ApprovalRequest::query()->findOrFail($record->response_id);
            }

            $locked = ApprovalRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isPending()) {
                throw ApprovalStateException::alreadyDecided($locked);
            }

            // Measured against the member who raised the request, and refused
            // whoever asks — including the Owner and the Financial Manager. The
            // check is a comparison rather than a role test, so it holds
            // regardless of which grants the caller happens to hold.
            if ($decidedByUserId !== null && $locked->requested_by_user_id === $decidedByUserId) {
                throw ApprovalStateException::selfApprovalRefused($locked);
            }

            $affected = ApprovalRequest::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    'status' => $decision->value,
                    'decided_by_user_id' => $decidedByUserId,
                    'decision_reason' => $reason,
                    'decided_at' => now(),
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    'Approval request '.$locked->ulid,
                    (int) $locked->row_version
                );
            }

            $decided = ApprovalRequest::query()->findOrFail($locked->getKey());

            $this->audit->record(
                $decision === ApprovalStatus::Approved ? 'approval.approved' : 'approval.rejected',
                $decided,
                ['status' => ApprovalStatus::Pending->value],
                [
                    'status' => $decision->value,
                    'decided_by_user_id' => $decidedByUserId,
                    'decision_reason' => $reason,
                ],
            );

            $this->notify($decided, $decision, $reason, $decidedByUserId);

            $this->completeKey($record, $decided, $decision);

            return $decided;
        });
    }

    /**
     * Tell the document's own module what became of it, in this same
     * transaction.
     *
     * A queue that only marked its own row would leave a decided document
     * looking undecided to the screen that owns it, so the module is told here
     * rather than left to poll. Only a module that has a life of its own needs
     * this: a journal is posted or it is not, but a budget plan is Draft,
     * Pending, Approved or Locked, and a decision is what moves it. A rollback
     * takes the module's change with it, so the queue and the document can never
     * disagree about what was decided.
     */
    private function notify(
        ApprovalRequest $request,
        ApprovalStatus $decision,
        ?string $reason,
        ?int $decidedByUserId,
    ): void {
        if ($request->document_type === ApprovalDocumentType::Budget) {
            app(BudgetVersionLifecycle::class)->applyDecision($request, $decision, $reason, $decidedByUserId);
        }
    }

    /**
     * The fingerprint is built from the request and the decision it answers, so
     * a key reused for the other decision, or for another request, is caught as
     * a client bug rather than replayed as a safe retry.
     */
    private function claimKey(
        ApprovalRequest $request,
        ApprovalStatus $decision,
        string $key,
    ): IdempotencyKey {
        $companyId = (int) $request->company_id;
        $requestHash = hash('sha256', self::SCOPE.'|'.$request->getKey().'|'.$decision->value);

        $existing = IdempotencyKey::query()
            ->where('company_id', $companyId)
            ->where('scope', self::SCOPE)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            try {
                return IdempotencyKey::create([
                    'company_id' => $companyId,
                    'scope' => self::SCOPE,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'status' => IdempotencyStatus::InProgress,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw IdempotencyConflictException::inProgress($key);
            }
        }

        if ($existing->request_hash !== $requestHash) {
            throw IdempotencyConflictException::payloadMismatch($key);
        }

        if ($existing->status === IdempotencyStatus::InProgress) {
            throw IdempotencyConflictException::inProgress($key);
        }

        if ($existing->status === IdempotencyStatus::Failed) {
            $existing->forceFill([
                'status' => IdempotencyStatus::InProgress,
                'completed_at' => null,
            ])->save();
        }

        return $existing;
    }

    private function completeKey(
        IdempotencyKey $record,
        ApprovalRequest $request,
        ApprovalStatus $decision,
    ): void {
        $record->forceFill([
            'status' => IdempotencyStatus::Completed,
            'response_type' => ApprovalRequest::class,
            'response_id' => $request->getKey(),
            'response_status' => 200,
            'response_body' => [
                'document_type' => $request->document_type->value,
                'document_reference' => $request->document_reference,
                'status' => $decision->value,
                'decision_reason' => $request->decision_reason,
            ],
            'completed_at' => now(),
        ])->save();
    }
}
