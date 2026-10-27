<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Journal;
use App\Models\User;

/**
 * Who may touch a journal, and for which company.
 *
 * Every method answers two separate questions, in this order: may this member
 * see the company whose book the journal sits in, and does their role in that
 * company hold the matching permission. Membership is checked by hand rather
 * than through the team-aware Gate because a policy can be reached with a
 * journal from a company the caller has never been a member of, and a role
 * check alone would not notice.
 */
class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_JOURNAL_VIEW);
    }

    public function view(User $user, Journal $journal): bool
    {
        return $this->worksFor($user, $journal)
            && $user->can(AccessMatrix::PERMISSION_JOURNAL_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_JOURNAL_CREATE);
    }

    public function post(User $user, Journal $journal): bool
    {
        return $this->worksFor($user, $journal)
            && $user->can(AccessMatrix::PERMISSION_JOURNAL_POST);
    }

    public function reverse(User $user, Journal $journal): bool
    {
        return $this->worksFor($user, $journal)
            && $user->can(AccessMatrix::PERMISSION_JOURNAL_REVERSE);
    }

    private function worksFor(User $user, Journal $journal): bool
    {
        return $user->belongsToCompany((int) $journal->company_id);
    }
}
