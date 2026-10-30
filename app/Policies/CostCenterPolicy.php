<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\CostCenter;
use App\Models\User;

/**
 * Who may maintain the cost centre list, and for which company.
 *
 * A cost centre exists only to slice a plan, so it is guarded by the budget
 * rights rather than by a set of its own: reading the list is part of reading
 * the plan, and changing it is part of writing one. This is the same split the
 * item catalogue makes between `stock.view` and `stock.manage`.
 */
class CostCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BUDGET_VIEW);
    }

    public function view(User $user, CostCenter $costCenter): bool
    {
        return $this->worksFor($user, $costCenter)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BUDGET_MANAGE);
    }

    public function update(User $user, CostCenter $costCenter): bool
    {
        return $this->worksFor($user, $costCenter)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_MANAGE);
    }

    private function worksFor(User $user, CostCenter $costCenter): bool
    {
        return $user->belongsToCompany((int) $costCenter->company_id);
    }
}
