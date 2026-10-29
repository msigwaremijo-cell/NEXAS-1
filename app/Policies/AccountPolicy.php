<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Account;
use App\Models\User;

/**
 * The chart of accounts and the ledger reports built from it.
 *
 * Reading the books is a report-level right, and every role in the matrix holds
 * it: an Auditor who cannot read the ledger cannot audit it. The separation
 * that actually matters here is company membership, which is checked by hand
 * because a policy can be reached with an account from a company the caller has
 * never been a member of, and a permission check alone would not notice.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy) because
 * AppServiceProvider registers nothing, so there is no mapping to keep in step.
 */
class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_REPORT_VIEW);
    }

    public function view(User $user, Account $account): bool
    {
        return $user->belongsToCompany((int) $account->company_id)
            && $user->can(AccessMatrix::PERMISSION_REPORT_VIEW);
    }

    /**
     * Changing how an account is classified, which is a decision about the
     * chart of accounts rather than about the books.
     *
     * Membership is checked by hand for the same reason as view(): the account
     * handed in may belong to a company the caller has never been a member of,
     * and a permission check alone would not notice.
     */
    public function manage(User $user, Account $account): bool
    {
        return $user->belongsToCompany((int) $account->company_id)
            && $user->can(AccessMatrix::PERMISSION_ACCOUNT_MANAGE);
    }
}
