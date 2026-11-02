<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * Who may read the approvals queue, raise a document into it and decide what is
 * waiting there, and for which company.
 *
 * Three separate abilities. view reads the queue, request puts a document in
 * it, decide answers it. Keeping them apart is what lets the matrix withhold
 * the decision from the member who raises the document — which is the control
 * the queue exists to be, and is enforced a second time in the action so no
 * code path can reach a decision without passing the no-self-approval rule.
 *
 * There is no update and no delete ability. A decision is final and the row is
 * kept as it was, so the decision is the only write there is.
 */
class ApprovalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_APPROVAL_VIEW);
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $this->worksFor($user, $request)
            && $user->can(AccessMatrix::PERMISSION_APPROVAL_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_APPROVAL_REQUEST);
    }

    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $this->worksFor($user, $request)
            && $user->can(AccessMatrix::PERMISSION_APPROVAL_DECIDE);
    }

    private function worksFor(User $user, ApprovalRequest $request): bool
    {
        return $user->belongsToCompany((int) $request->company_id);
    }
}
