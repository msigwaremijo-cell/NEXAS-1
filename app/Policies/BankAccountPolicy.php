<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\BankAccount;
use App\Models\User;

/**
 * The list of places the company keeps money.
 *
 * Reading it is a report-level right: a cashier has to know which till they are
 * banking into. Opening one is not, because a new bank account is a new ledger
 * account to post through, and inventing one is how money leaves a set of books
 * quietly.
 *
 * Company membership is checked by hand as well as by the permission, because
 * this policy can be reached with an account from a company the caller has never
 * belonged to, and a permission check alone would not notice.
 */
class BankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BANK_VIEW);
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $user->belongsToCompany((int) $bankAccount->company_id)
            && $user->can(AccessMatrix::PERMISSION_BANK_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_BANK_MANAGE);
    }
}
