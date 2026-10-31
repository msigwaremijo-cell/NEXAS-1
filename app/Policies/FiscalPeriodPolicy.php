<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\FiscalPeriod;
use App\Models\User;

/**
 * Who may read the fiscal calendar and who may reopen a period in it.
 *
 * Reading the calendar is `report.view`: which periods exist, which are still
 * open and which have been closed is part of what the books report, and every
 * role holds that right. The reopen is `fiscal_period.reopen`, held by the
 * Owner alone, because it puts a period that has already been reported back
 * into the books.
 *
 * Membership is checked by hand on `reopen` for the same reason as the other
 * row-scoped policies: the period is named by the request, and a policy can be
 * reached with a period from a company the caller has never been a member of,
 * where a permission check alone would not notice.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy).
 */
class FiscalPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_REPORT_VIEW);
    }

    public function reopen(User $user, FiscalPeriod $period): bool
    {
        return $user->belongsToCompany((int) $period->company_id)
            && $user->can(AccessMatrix::PERMISSION_FISCAL_PERIOD_REOPEN);
    }
}
