<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\User;

/**
 * Who may administer the members of a company.
 *
 * Only `user.manage` may, and the Owner is the only role that holds it: the
 * person who can hand out a role can hand out the ability to approve bills and
 * close years, so that right is the most sensitive grant in the matrix and is
 * deliberately not folded into any of the others.
 *
 * Every ability below is also a statement about the company in play. The
 * `company` middleware has already refused a company the caller does not belong
 * to and left the pinned company id on the permission resolver, which is how
 * these methods can tell whether the member being administered is one of this
 * company's own rather than somebody else's member.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_USER_MANAGE);
    }

    public function view(User $user, User $member): bool
    {
        return $this->inCompany($member)
            && $user->can(AccessMatrix::PERMISSION_USER_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_USER_MANAGE);
    }

    public function manageRoles(User $user, User $member): bool
    {
        return $this->inCompany($member)
            && $user->can(AccessMatrix::PERMISSION_USER_MANAGE);
    }

    public function manageStatus(User $user, User $member): bool
    {
        return $this->inCompany($member)
            && $user->can(AccessMatrix::PERMISSION_USER_MANAGE);
    }

    /**
     * Whether the member being administered belongs to the company the request
     * is pinned to. Nobody administers a member of a company they are not in,
     * however privileged they are in their own.
     */
    private function inCompany(User $member): bool
    {
        $companyId = getPermissionsTeamId();

        return $companyId !== null && $member->belongsToCompany((int) $companyId);
    }
}
