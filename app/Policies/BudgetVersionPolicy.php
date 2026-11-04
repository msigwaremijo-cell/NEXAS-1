<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\BudgetVersion;
use App\Models\User;

/**
 * Who may move a company's plan through its life.
 *
 * Where {@see BudgetPolicy} guards what a plan *is* — reading it and writing
 * it — this guards what happens to a version once it exists: raising it for a
 * decision, closing it and reopening it. They are separate policies because
 * they guard separate rows: the plan the reports read is one row per period,
 * while a version is one row per authored state of a whole fiscal year, and a
 * member may be trusted with one and not the other.
 *
 * Each ability is a right of its own rather than a consequence of writing the
 * plan, because proposing a plan, agreeing to it and declaring it settled are
 * three different acts. The no-self-approval rule is not expressed here — it
 * belongs to the queue, where a decision is measured against the member who
 * raised the document — so this policy answers only "may this member perform
 * this act at all", and the state machine answers "may it be performed now".
 *
 * Membership is checked by hand as well as by permission, for the same reason
 * as every other row-scoped policy: route model binding resolves the version by
 * its public id across every company, and the company middleware has already
 * settled which company is in play. A permission check alone would not notice
 * that the two disagree.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy).
 */
class BudgetVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BUDGET_VIEW);
    }

    public function view(User $user, BudgetVersion $version): bool
    {
        return $user->belongsToCompany((int) $version->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_VIEW);
    }

    /**
     * Writing a plan for a fiscal year that has none yet.
     *
     * A user-only ability because there is no row to scope: the fiscal year is
     * resolved inside the caller's company before this is reached, so
     * membership is already settled.
     */
    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BUDGET_MANAGE);
    }

    /**
     * Raising a draft plan into the approvals queue.
     */
    public function submit(User $user, BudgetVersion $version): bool
    {
        return $user->belongsToCompany((int) $version->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_SUBMIT);
    }

    /**
     * Answering a plan that is waiting on a decision.
     *
     * This is the door the Owner approves a plan through. The shared queue's
     * own decision route is guarded by `approval.decide`, a right the Owner
     * deliberately does not hold — the queue's second pair of eyes is the
     * Financial Manager's — so a plan the Owner must be able to agree to needs a
     * right of its own. It is still the queue's decision machinery that runs:
     * the request is decided by the same action, with the same idempotency, the
     * same finality and the same no-self-approval rule. What differs is only who
     * may reach the door.
     */
    public function approve(User $user, BudgetVersion $version): bool
    {
        return $user->belongsToCompany((int) $version->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_APPROVE);
    }

    /**
     * Closing an approved plan.
     */
    public function lock(User $user, BudgetVersion $version): bool
    {
        return $user->belongsToCompany((int) $version->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_LOCK);
    }

    /**
     * Reopening a closed plan. Guarded by the same right as the lock it
     * reverses: the member trusted to declare a plan settled is the member
     * trusted to say it is not.
     */
    public function unlock(User $user, BudgetVersion $version): bool
    {
        return $user->belongsToCompany((int) $version->company_id)
            && $user->can(AccessMatrix::PERMISSION_BUDGET_LOCK);
    }
}
