<?php

namespace App\Domain\Accounting\Exceptions;

use App\Models\FiscalYear;

/**
 * The year exists but is not in the state the caller assumed.
 *
 * Closing a year that is already closed is not a malformed request, so it is
 * not a 422: the request was well formed when it was sent, and the state of the
 * books has moved on since. Nor is it something to retry unchanged.
 */
final class FiscalYearStateException extends AccountingException
{
    public static function notOpen(FiscalYear $year): self
    {
        return new self(sprintf(
            'Fiscal year %s is %s, so it cannot be closed. Only an open year can be closed.',
            $year->name,
            $year->status->value
        ));
    }

    /**
     * The chart has no postable account to close into. This is a setup fault
     * rather than a bad request, which is why it is a state refusal and not a
     * validation error: no change to the request would make it succeed.
     */
    public static function retainedEarningsMissing(int $companyId, string $code): self
    {
        return new self(sprintf(
            'Company %d has no postable account with code %s, so there is nothing to close the year into.',
            $companyId,
            $code
        ));
    }
}
