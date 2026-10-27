<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Customer;
use App\Models\User;

/**
 * Who may maintain the customer list, and for which company.
 *
 * Reading is separated from maintaining: a Cashier or an Auditor must be able
 * to look a customer up while taking a receipt or reading the ledger, but
 * neither should be able to invent a debtor.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_PARTY_VIEW);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->worksFor($user, $customer)
            && $user->can(AccessMatrix::PERMISSION_PARTY_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_PARTY_MANAGE);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->worksFor($user, $customer)
            && $user->can(AccessMatrix::PERMISSION_PARTY_MANAGE);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->worksFor($user, $customer)
            && $user->can(AccessMatrix::PERMISSION_PARTY_MANAGE);
    }

    private function worksFor(User $user, Customer $customer): bool
    {
        return $user->belongsToCompany((int) $customer->company_id);
    }
}
