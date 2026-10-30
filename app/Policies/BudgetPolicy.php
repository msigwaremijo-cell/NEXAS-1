<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Budget;
use App\Models\User;

/**
 * Who may read and write a company's plan.
 *
 * A budget is not the books, so the right that guards it is `budget.view`
 * rather than `report.view`: a member may be trusted to read what the ledger
 * holds without being shown what the company intends. Writing it is
 * `budget.manage`, and nothing in either ability reaches the ledger — a plan is
 * a statement of intent, so this policy is the whole of the guard on it.
 *
 * Membership is checked by hand for the same reason as the other row-scoped
 * policies: a policy can be reached with a budget from a company the caller has
 * never been a member of, and a permission check alone would not notice.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy).
 */
class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BUDGET_VIEW);
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->belongsToCompany((int) $budget->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_VIEW);
    }

    /**
     * Writing a plan for a period that has none yet.
     *
     * A user-only ability because there is no row to scope: the period the
     * plan covers is resolved inside the caller's company before this is
     * reached, so membership is already settled.
     */
    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BUDGET_MANAGE);
    }

    /**
     * Rewriting a plan that already exists.
     */
    public function manage(User $user, Budget $budget): bool
    {
        return $user->belongsToCompany((int) $budget->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_MANAGE);
    }
}
