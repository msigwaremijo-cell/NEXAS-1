<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\User;

/**
 * Who may read and change the policy a company keeps its books under.
 *
 * Membership is not checked here the way the row-scoped policies check it,
 * because the company is never named by the request: the `company` middleware
 * has already resolved it from the member's default or the X-Company-Id header
 * and refused a company the caller does not belong to. By the time a controller
 * method runs, there is exactly one company in play and the caller is a member
 * of it, so what is left to decide is the permission alone.
 *
 * Reading is behind the same right as writing. The two settings are a pair of
 * booleans about how the accounts are kept rather than business data, and
 * nobody who is not trusted to change them has a use for them.
 */
class CompanyPolicy
{
    public function viewSettings(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_COMPANY_MANAGE);
    }

    public function updateSettings(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_COMPANY_MANAGE);
    }
}
