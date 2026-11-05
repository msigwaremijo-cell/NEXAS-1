<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Approvals\Actions\DecideApprovalRequest;
use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Reporting\Enums\BudgetStatus;
use App\Domain\Reporting\Exceptions\BudgetStateException;
use App\Models\ApprovalRequest;
use App\Models\BudgetVersion;
use App\Models\IdempotencyKey;
use App\Support\Enums\IdempotencyStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Moves a plan through its life: raised, decided, locked and unlocked.
 *
 * A budget version is written by {@see BudgetVersionStore} and this class is
 * what happens to it afterwards. Every move is one act by one named member,
 * written inside one transaction, stamped into the audit chain and guarded on
 * `row_version` so two members acting at once cannot both believe they had the
 * last word. Nothing here is a posting: a plan that is approved and locked has
 * still posted no journal and moved no balance.
 *
 * Raising a plan puts it in the queue every other document waits in — the
 * `approval_requests` row is the same row a journal or a supplier bill gets, so
 * the approver sees one line rather than a second inbox. The decision itself is
 * made by {@see DecideApprovalRequest}, which is
 * where the no-self-approval rule and the finality of a decision live; this
 * class is told the outcome and moves the plan to match it.
 *
 * A rejected plan comes back to Draft rather than being frozen, because the
 * member who wrote it is being asked to correct it. The rejection's reason is
 * kept on the version, and the approval request is re-opened rather than
 * duplicated when the plan is raised again — a document is queued once, and a
 * plan that has been sent back is the same document waiting a second time.
 */
final class BudgetVersionLifecycle
{
    private const SCOPE_SUBMIT = 'budget.submit';

    private const SCOPE_LOCK = 'budget.lock';

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Raise a draft plan for a decision.
     */
    public function submit(BudgetVersion $version, int $userId, ?string $idempotencyKey = null): BudgetVersion
    {
        return DB::transaction(function () use ($version, $userId, $idempotencyKey): BudgetVersion {
            $companyId = (int) $version->company_id;
            $record = $this->claim(self::SCOPE_SUBMIT, 'submit|'.$version->getKey(), $companyId, $idempotencyKey);

            if ($record !== null && $record->status->isReplayable()) {
                return BudgetVersion::query()->findOrFail($record->response_id)->load('lines');
            }

            $locked = $this->lock($version);

            if ($locked->status === BudgetStatus::PendingApproval) {
                throw BudgetStateException::alreadySubmitted($locked);
            }

            if (! $locked->status->isSubmittable()) {
                throw BudgetStateException::notSubmittable($locked);
            }

            $this->queue($locked, $userId);
            $submitted = $this->move($locked, [
                'status' => BudgetStatus::PendingApproval->value,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
            ]);

            $this->audit->record('budget.submitted', $submitted, [
                'status' => $locked->status->value,
            ], [
                'status' => BudgetStatus::PendingApproval->value,
                'submitted_by_user_id' => $userId,
            ]);

            $this->complete($record, $submitted);

            return $submitted->load('lines');
        });
    }

    /**
     * Move the plan to match a decision the queue has already made.
     *
     * Called by the approvals module rather than by a budget route, so a plan
     * decided from the shared queue and one decided from the budget screen end
     * in the same state. An approval is accepted whether or not the member who
     * decided it is the member who wrote the plan — the no-self-approval rule is
     * measured at the queue against the member who raised it, and is tightened
     * here against the plan's author as well, so a colleague cannot approve on
     * the author's behalf only to hand the author their own signature back.
     */
    public function applyDecision(
        ApprovalRequest $request,
        ApprovalStatus $decision,
        ?string $reason,
        ?int $decidedByUserId,
    ): void {
        DB::transaction(function () use ($request, $decision, $reason, $decidedByUserId): void {
            $version = BudgetVersion::query()
                ->where('company_id', $request->company_id)
                ->whereKey($request->document_id)
                ->lockForUpdate()
                ->first();

            if ($version === null) {
                return;
            }

            if ($decidedByUserId !== null
                && in_array($decidedByUserId, array_filter([
                    $version->created_by_user_id,
                    $version->submitted_by_user_id,
                ]), true)) {
                throw BudgetStateException::selfApprovalRefused($version);
            }

            if ($decision === ApprovalStatus::Approved) {
                $moved = $this->move($version, [
                    'status' => BudgetStatus::Approved->value,
                    'decided_by_user_id' => $decidedByUserId,
                    'decided_at' => now(),
                    'decision_reason' => $reason,
                ]);

                $this->audit->record('budget.approved', $moved, [
                    'status' => $version->status->value,
                ], [
                    'status' => BudgetStatus::Approved->value,
                    'decided_by_user_id' => $decidedByUserId,
                ]);

                return;
            }

            $moved = $this->move($version, [
                'status' => BudgetStatus::Draft->value,
                'decided_by_user_id' => $decidedByUserId,
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->record('budget.rejected', $moved, [
                'status' => $version->status->value,
            ], [
                'status' => BudgetStatus::Draft->value,
                'decision_reason' => $reason,
            ]);
        });
    }

    /**
     * Close an approved plan. A locked plan may not be edited and no further
     * version may be started from it.
     */
    public function lockVersion(BudgetVersion $version, int $userId, ?string $idempotencyKey = null): BudgetVersion
    {
        return DB::transaction(function () use ($version, $userId, $idempotencyKey): BudgetVersion {
            $companyId = (int) $version->company_id;
            $record = $this->claim(self::SCOPE_LOCK, 'lock|'.$version->getKey(), $companyId, $idempotencyKey);

            if ($record !== null && $record->status->isReplayable()) {
                return BudgetVersion::query()->findOrFail($record->response_id)->load('lines');
            }

            $locked = $this->lock($version);

            if (! $locked->status->isLockable()) {
                throw BudgetStateException::notLockable($locked);
            }

            $closed = $this->move($locked, [
                'status' => BudgetStatus::Locked->value,
                'locked_by_user_id' => $userId,
                'locked_at' => now(),
            ]);

            $this->audit->record('budget.locked', $closed, [
                'status' => $locked->status->value,
            ], [
                'status' => BudgetStatus::Locked->value,
                'locked_by_user_id' => $userId,
            ]);

            $this->complete($record, $closed);

            return $closed->load('lines');
        });
    }

    /**
     * Reopen a locked plan so the next version may be started from it. The plan
     * returns to Approved: unlocking is undoing the close, not undoing the
     * decision, and a plan nobody agreed to is not one anybody may build on.
     */
    public function unlock(BudgetVersion $version, int $userId, ?string $idempotencyKey = null): BudgetVersion
    {
        return DB::transaction(function () use ($version, $userId, $idempotencyKey): BudgetVersion {
            $companyId = (int) $version->company_id;
            $record = $this->claim(self::SCOPE_LOCK, 'unlock|'.$version->getKey(), $companyId, $idempotencyKey);

            if ($record !== null && $record->status->isReplayable()) {
                return BudgetVersion::query()->findOrFail($record->response_id)->load('lines');
            }

            $locked = $this->lock($version);

            if ($locked->status !== BudgetStatus::Locked) {
                throw BudgetStateException::notLocked($locked);
            }

            $reopened = $this->move($locked, [
                'status' => BudgetStatus::Approved->value,
                'locked_by_user_id' => null,
                'locked_at' => null,
            ]);

            $this->audit->record('budget.unlocked', $reopened, [
                'status' => BudgetStatus::Locked->value,
            ], [
                'status' => BudgetStatus::Approved->value,
                'unlocked_by_user_id' => $userId,
            ]);

            $this->complete($record, $reopened);

            return $reopened->load('lines');
        });
    }

    /**
     * Put the plan in the approvals queue, re-opening the request it already has
     * when that request was refused. A document is queued once, so a plan that
     * was sent back is the same document waiting a second time rather than a
     * second document — and the decision it already carries stays in the audit
     * chain, which is where the history of a decision belongs.
     */
    private function queue(BudgetVersion $version, int $userId): ApprovalRequest
    {
        $existing = ApprovalRequest::query()
            ->where('company_id', $version->company_id)
            ->where('document_type', ApprovalDocumentType::Budget)
            ->where('document_id', $version->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            try {
                $request = ApprovalRequest::query()->create([
                    'company_id' => (int) $version->company_id,
                    'document_type' => ApprovalDocumentType::Budget,
                    'document_id' => $version->getKey(),
                    'document_ulid' => $version->ulid,
                    'document_reference' => $this->reference($version),
                    'status' => ApprovalStatus::Pending,
                    'requested_by_user_id' => $userId,
                    'row_version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw BudgetStateException::alreadySubmitted($version);
            }

            $this->audit->record('approval.requested', $request, null, [
                'document_type' => ApprovalDocumentType::Budget->value,
                'document_reference' => $request->document_reference,
                'status' => ApprovalStatus::Pending->value,
                'requested_by_user_id' => $userId,
            ]);

            return $request;
        }

        if ($existing->status === ApprovalStatus::Pending) {
            throw BudgetStateException::alreadySubmitted($version);
        }

        if ($existing->status === ApprovalStatus::Approved) {
            throw BudgetStateException::notSubmittable($version);
        }

        $before = $existing->status->value;

        $existing->forceFill([
            'requested_by_user_id' => $userId,
            'decided_by_user_id' => null,
            'decision_reason' => null,
            'decided_at' => null,
            'status' => ApprovalStatus::Pending,
            'row_version' => (int) $existing->row_version + 1,
            'updated_at' => now(),
        ])->save();

        $this->audit->record('approval.requested', $existing, [
            'status' => $before,
        ], [
            'document_type' => ApprovalDocumentType::Budget->value,
            'document_reference' => $existing->document_reference,
            'status' => ApprovalStatus::Pending->value,
            'requested_by_user_id' => $userId,
        ]);

        return $existing;
    }

    private function reference(BudgetVersion $version): string
    {
        $version->loadMissing('fiscalYear');

        return sprintf(
            '%s · version %d',
            (string) $version->fiscalYear->name,
            (int) $version->version_no,
        );
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function move(BudgetVersion $version, array $after): BudgetVersion
    {
        $affected = BudgetVersion::query()
            ->whereKey($version->getKey())
            ->where('row_version', $version->row_version)
            ->update($after + [
                'row_version' => (int) $version->row_version + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw ConcurrentModificationException::row(
                sprintf('Budget version %d for %s', $version->version_no, $this->yearName($version)),
                (int) $version->row_version,
            );
        }

        return BudgetVersion::query()->findOrFail($version->getKey());
    }

    private function lock(BudgetVersion $version): BudgetVersion
    {
        return BudgetVersion::query()
            ->whereKey($version->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function claim(string $scope, string $fingerprint, int $companyId, ?string $key): ?IdempotencyKey
    {
        if ($key === null || $key === '') {
            return null;
        }

        $requestHash = hash('sha256', $scope.'|'.$fingerprint);

        $existing = IdempotencyKey::query()
            ->where('company_id', $companyId)
            ->where('scope', $scope)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            try {
                return IdempotencyKey::create([
                    'company_id' => $companyId,
                    'scope' => $scope,
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

    private function complete(?IdempotencyKey $record, BudgetVersion $version): void
    {
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => IdempotencyStatus::Completed,
            'response_type' => BudgetVersion::class,
            'response_id' => $version->getKey(),
            'response_status' => 200,
            'response_body' => [
                'version_no' => (int) $version->version_no,
                'status' => $version->status->value,
            ],
            'completed_at' => now(),
        ])->save();
    }

    private function yearName(BudgetVersion $version): string
    {
        $version->loadMissing('fiscalYear');

        return (string) $version->fiscalYear->name;
    }
}
