<?php

namespace App\Domain\Files\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * Base type for everything the file domain refuses.
 *
 * A document that is the wrong kind, one whose contents contradict the name it
 * was filed under, one too large to keep, one a scanner has condemned, one
 * that could not be written to the store. All of them are the caller's to
 * correct, so they are 422s — except that the last is nobody's to correct, and
 * is named the same way only so a failed write reads as a refusal rather than
 * as a blank.
 */
class FilesException extends AccountingException
{
    /**
     * The file passed every check and the store still would not take it.
     *
     * There is nothing the caller can change about their request to fix this,
     * which is why it does not read like the refusals beside it; it is here so
     * a failed write answers with a sentence rather than a blank.
     */
    public static function notStored(string $name): self
    {
        return new self(sprintf(
            'The file "%s" could not be written to the store, so nothing was filed.',
            $name
        ));
    }

    /**
     * A document was pointed at a kind of record this build cannot file one
     * against.
     *
     * @param  list<string>  $known
     */
    public static function unsupportedTarget(string $type, array $known): self
    {
        return new self(sprintf(
            'A document cannot be filed against a "%s". This build files documents against: %s.',
            $type,
            $known === [] ? '(nothing)' : implode(', ', $known)
        ));
    }
}
