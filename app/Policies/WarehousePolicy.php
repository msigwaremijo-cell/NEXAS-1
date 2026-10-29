<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use App\Models\Warehouse;

/**
 * Who may maintain the warehouse list, and for which company.
 *
 * Same split as the item catalogue: a warehouse is master data that movements
 * point at, so reading it is a stock right and changing it is a stock
 * management right.
 */
class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_STOCK_VIEW);
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->worksFor($user, $warehouse)
            && $user->can(AccessMatrix::PERMISSION_STOCK_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_STOCK_MANAGE);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->worksFor($user, $warehouse)
            && $user->can(AccessMatrix::PERMISSION_STOCK_MANAGE);
    }

    private function worksFor(User $user, Warehouse $warehouse): bool
    {
        return $user->belongsToCompany((int) $warehouse->company_id);
    }
}
