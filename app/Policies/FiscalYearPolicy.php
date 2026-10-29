<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\FiscalYear;
use App\Models\User;

/**
 * Who may close a year.
 *
 * Membership is checked by hand because the year is named by the request and a
 * policy can be reached with a year from a company the caller has never been a
 * member of, where a permission check alone would not notice.
 *
 * The right is deliberately its own rather than folded into the journal rights.
 * Posting a journal is a daily act that can be corrected by posting another one;
 * closing a year moves the year's whole result out of revenue and expense and
 * stops the year accepting anything further, so it is granted on purpose rather
 * than inherited from being allowed to keep the books.
 */
class FiscalYearPolicy
{
    public function close(User $user, FiscalYear $year): bool
    {
        return $user->belongsToCompany((int) $year->company_id)
            && $user->can(AccessMatrix::PERMISSION_FISCAL_YEAR_CLOSE);
    }
}
