<?php

namespace App\Domain\Approvals\Exceptions;

use App\Models\ApprovalRequest;

/**
 * A decision that collides with the state of the queue.
 *
 * The request itself is well formed, so resending it unchanged will produce the
 * same answer every time: this is a 409 rather than the 422 its parent throws.
 */
final class ApprovalStateException extends ApprovalsException
{
    /**
     * The member deciding is the member who raised the document.
     *
     * Approval is the control that puts a second pair of eyes between a
     * document and the books, so it is refused whoever asks — the check is
     * global rather than a grant, so adding a role can never accidentally hand
     * somebody back the right to sign off their own work.
     */
    public static function selfApprovalRefused(ApprovalRequest $request): self
    {
        return new self(sprintf(
            '%s %s was raised for approval by the member now deciding it, and nobody may approve their own entry.',
            $request->document_type->label(),
            self::name($request)
        ));
    }

    /**
     * A decision asked for against a request that already carries one. A
     * decision is final, so the second one is refused rather than treated as a
     * change of mind.
     */
    public static function alreadyDecided(ApprovalRequest $request): self
    {
        return new self(sprintf(
            '%s %s has already been %s, and a decision is final.',
            $request->document_type->label(),
            self::name($request),
            strtolower($request->status->label())
        ));
    }

    /**
     * The document is already in the queue. A document is queued once, so a
     * second raise collides with the row already there.
     */
    public static function alreadyQueued(string $documentType, string $reference): self
    {
        return new self(sprintf(
            '%s %s is already in the approvals queue, and a document is queued once.',
            $documentType,
            $reference
        ));
    }

    /**
     * The human handle for a queued document: its own number where the module
     * records one, and the ULID where it does not.
     */
    private static function name(ApprovalRequest $request): string
    {
        return $request->document_reference ?? $request->document_ulid;
    }
}
