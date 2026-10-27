<?php

namespace App\Domain\Accounting\Exceptions;

/**
 * The idempotency key cannot be honoured: it is already in flight, or it was
 * first used for a different request. Reusing a key is only ever a retry of
 * the identical request; anything else is a client bug rather than a safe
 * duplicate.
 */
final class IdempotencyConflictException extends AccountingException
{
    public static function inProgress(string $key): self
    {
        return new self(sprintf(
            'Idempotency key [%s] is already in progress for this company.',
            $key
        ));
    }

    public static function payloadMismatch(string $key): self
    {
        return new self(sprintf(
            'Idempotency key [%s] was already used for a different request. '
            .'Reuse a key only to retry the identical request.',
            $key
        ));
    }
}
