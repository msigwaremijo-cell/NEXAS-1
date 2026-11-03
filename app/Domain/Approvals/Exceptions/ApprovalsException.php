<?php

namespace App\Domain\Approvals\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * A refusal from the approvals queue that the caller can fix and resubmit: a
 * document that cannot be found inside the caller's company, a rejection that
 * carries no reason, a decision that carries no idempotency key.
 *
 * It extends the accounting base class so the status mapping in
 * bootstrap/app.php reaches it through the registration that is already there:
 * a refusal the caller can act on is a 422.
 */
class ApprovalsException extends AccountingException
{
    public static function unknownDocument(string $documentType, string $ulid): self
    {
        return new self(sprintf(
            'No %s document with identifier %s exists in this company, so there is nothing to queue for approval.',
            $documentType,
            $ulid
        ));
    }

    /**
     * A rejection has to say why.
     *
     * An approval needs no explanation — the document stands on its own — but a
     * rejection is the only thing the member who raised the document is given
     * to act on, so a bare "rejected" would leave them guessing.
     */
    public static function reasonRequired(): self
    {
        return new self(
            'A rejection must carry a reason: say why the document was refused, so the member who raised it can act on it.'
        );
    }

    /**
     * A decision has to name the attempt it answers.
     *
     * Requiring the key rather than accepting one optionally is what makes the
     * rule "every decision is idempotent" true of the surface rather than true
     * of the clients that choose to honour it: a retried approval cannot quietly
     * decide a document a second time.
     */
    public static function idempotencyKeyRequired(): self
    {
        return new self(
            'A decision must carry an idempotency key, so a retried approval cannot decide the same document twice.'
        );
    }

    /**
     * A bulk approval may name no more documents than the server allows.
     *
     * The ceiling is checked against the batch the server received rather than
     * against the count the screen drew, so a caller cannot raise it by
     * editing what is sent. It is a pre-flight refusal: nothing in the batch is
     * decided when the batch itself is too large.
     */
    public static function bulkTooManyItems(int $count, int $max): self
    {
        return new self(sprintf(
            'A bulk approval may name at most %d documents; this one names %d. Decide them in smaller batches.',
            $max,
            $count
        ));
    }

    /**
     * A bulk approval may carry no more value than the server allows.
     *
     * Measured on the documents the batch really names, read inside the
     * caller's company, so naming a document that cannot be resolved cannot be
     * used to keep the total under the ceiling. Like the item count, this is a
     * pre-flight refusal rather than a per-item one.
     */
    public static function bulkOverLimit(string $total, float $limit): self
    {
        return new self(sprintf(
            'A bulk approval may carry at most %s in total; the documents named are worth %s. Decide them in smaller batches.',
            number_format($limit, 2),
            number_format((float) $total, 2)
        ));
    }
}
