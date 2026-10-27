<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Bill;
use App\Models\User;

/**
 * Who may raise, approve and post a supplier bill, and for which company.
 *
 * Four separate abilities, because a bill passes through four gates. manage
 * writes the draft, approve is the control that a named person accepted the
 * liability, post pushes it into the ledger, and view reads it. Keeping them
 * apart is what lets the matrix withhold approval from the accountant who
 * enters the bill while still letting them do the rest of their job.
 *
 * As on Invoice there is no delete ability: an approved bill is a liability
 * and is cancelled by a credit note, not by deleting the row.
 */
class BillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BILL_VIEW);
    }

    public function view(User $user, Bill $bill): bool
    {
        return $this->worksFor($user, $bill)
            && $user->can(AccessMatrix::PERMISSION_BILL_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BILL_MANAGE);
    }

    public function update(User $user, Bill $bill): bool
    {
        return $this->worksFor($user, $bill)
            && $user->can(AccessMatrix::PERMISSION_BILL_MANAGE);
    }

    public function submit(User $user, Bill $bill): bool
    {
        return $this->worksFor($user, $bill)
            && $user->can(AccessMatrix::PERMISSION_BILL_MANAGE);
    }

    public function approve(User $user, Bill $bill): bool
    {
        return $this->worksFor($user, $bill)
            && $user->can(AccessMatrix::PERMISSION_BILL_APPROVE);
    }

    public function post(User $user, Bill $bill): bool
    {
        return $this->worksFor($user, $bill)
            && $user->can(AccessMatrix::PERMISSION_BILL_POST);
    }

    private function worksFor(User $user, Bill $bill): bool
    {
        return $user->belongsToCompany((int) $bill->company_id);
    }
}
