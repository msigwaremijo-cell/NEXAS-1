<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Supplier;
use App\Models\User;

/**
 * Who may maintain the supplier list, and for which company.
 *
 * Mirrors CustomerPolicy deliberately: the two lists carry the same risk, so
 * they are governed by the same pair of permissions.
 */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_PARTY_VIEW);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->worksFor($user, $supplier)
            && $user->can(AccessMatrix::PERMISSION_PARTY_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_PARTY_MANAGE);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->worksFor($user, $supplier)
            && $user->can(AccessMatrix::PERMISSION_PARTY_MANAGE);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->worksFor($user, $supplier)
            && $user->can(AccessMatrix::PERMISSION_PARTY_MANAGE);
    }

    private function worksFor(User $user, Supplier $supplier): bool
    {
        return $user->belongsToCompany((int) $supplier->company_id);
    }
}
