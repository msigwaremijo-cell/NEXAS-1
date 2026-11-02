<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Domain\Approvals\Exceptions\ApprovalStateException;
use App\Models\ApprovalRequest;
use App\Models\IdempotencyKey;
use App\Support\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts a document into the approvals queue.
 *
 * The document is resolved inside the caller's own company before anything is
 * written, so a document named from another tenant is a refusal rather than a
 * queued admission that it exists. The queue row records the member raising it,
 * which is the author the no-self-approval rule is measured against: the rule
 * is about who put this document forward for a decision, not about which module
 * happened to write the document first.
 *
 * Raising a request writes no entry into the ledger and changes nothing about
 * the document itself. It is a note that says "this one is ready to be looked
 * at", which is why it is not gated behind a posting right.
 */
final class SubmitApprovalRequest
{
    private const SCOPE = 'approval.submit';

    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(
        int $companyId,
        ApprovalDocumentType $documentType,
        string $documentUlid,
        ?int $requestedByUserId = null,
        ?string $idempotencyKey = null,
    ): ApprovalRequest {
        return DB::transaction(function () use (
            $companyId,
            $documentType,
            $documentUlid,
            $requestedByUserId,
            $idempotencyKey
        ): ApprovalRequest {
            // The key is claimed before the document is resolved, so a retry of
            // the identical raise replays the first answer instead of colliding
            // with the row the first attempt wrote.
            $record = $idempotencyKey === null
                ? null
                : $this->claimKey($documentType, $documentUlid, $idempotencyKey, $companyId);

            if ($record !== null && $record->status->isReplayable()) {
                return ApprovalRequest::query()->findOrFail($record->response_id);
            }

            $document = $this->resolveDocument($companyId, $documentType, $documentUlid);

            if ($document === null) {
                throw ApprovalsException::unknownDocument($documentType->value, $documentUlid);
            }

            $reference = $this->reference($documentType, $document);

            try {
                $request = ApprovalRequest::create([
                    'company_id' => $companyId,
                    'document_type' => $documentType,
                    'document_id' => $document->getKey(),
                    'document_ulid' => $documentUlid,
                    'document_reference' => $reference,
                    'status' => ApprovalStatus::Pending,
                    'requested_by_user_id' => $requestedByUserId,
                    'row_version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A second raise of the same document. The unique key is what
                // catches the race; the exception is what turns it into a
                // sentence rather than a SQLSTATE.
                throw ApprovalStateException::alreadyQueued($documentType->label(), $reference ?? $documentUlid);
            }

            $this->audit->record('approval.requested', $request, null, [
                'document_type' => $documentType->value,
                'document_reference' => $reference,
                'status' => ApprovalStatus::Pending->value,
                'requested_by_user_id' => $requestedByUserId,
            ]);

            $this->completeKey($record, $request);

            return $request;
        });
    }

    private function resolveDocument(
        int $companyId,
        ApprovalDocumentType $documentType,
        string $documentUlid,
    ): ?Model {
        $class = $documentType->modelClass();
        $column = $documentType->documentTypeColumn();

        return $class::query()
            ->where('company_id', $companyId)
            ->where('ulid', $documentUlid)
            ->when(
                $column !== null,
                fn ($query) => $query->where('document_type', $column),
            )
            ->first();
    }

    /**
     * The human handle for the document. A journal prints its journal number, a
     * payment its payment number, the three kinds that share the `bills` table
     * their bill number and an asset its code, so the queue reads as something a
     * person can recognise rather than as a list of opaque identifiers.
     */
    private function reference(ApprovalDocumentType $documentType, Model $document): ?string
    {
        $value = match ($documentType) {
            ApprovalDocumentType::Journal => $document->getAttribute('journal_no'),
            ApprovalDocumentType::Bill,
            ApprovalDocumentType::CreditNote,
            ApprovalDocumentType::DebitNote => $document->getAttribute('bill_no'),
            ApprovalDocumentType::Payment => $document->getAttribute('payment_no'),
            ApprovalDocumentType::AssetDisposal => $document->getAttribute('code'),
        };

        return is_string($value) ? $value : null;
    }

    /**
     * The fingerprint is built from what the request names rather than from the
     * row it will write, so a retry of the same raise produces the same hash
     * even though the request's own row_version moves once it succeeds.
     */
    private function claimKey(
        ApprovalDocumentType $documentType,
        string $documentUlid,
        string $key,
        int $companyId,
    ): IdempotencyKey {
        $requestHash = hash('sha256', self::SCOPE.'|'.$documentType->value.'|'.$documentUlid);

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

    private function completeKey(?IdempotencyKey $record, ApprovalRequest $request): void
    {
        if ($record === null) {
            return;
        }

        $record->forceFill([
            'status' => IdempotencyStatus::Completed,
            'response_type' => ApprovalRequest::class,
            'response_id' => $request->getKey(),
            'response_status' => 201,
            'response_body' => [
                'document_type' => $request->document_type->value,
                'document_reference' => $request->document_reference,
                'status' => $request->status->value,
            ],
            'completed_at' => now(),
        ])->save();
    }
}
