<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Product;
use App\Models\User;

/**
 * Who may maintain the item catalogue, and for which company.
 *
 * Reading is separate from maintaining: a Cashier answers "have we got any" and
 * an Auditor reads what the stock is worth, but neither invents an item.
 * Membership is checked by hand as well as by permission, because a policy can
 * be reached with a product from a company the caller has never belonged to.
 */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_STOCK_VIEW);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->worksFor($user, $product)
            && $user->can(AccessMatrix::PERMISSION_STOCK_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_STOCK_MANAGE);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->worksFor($user, $product)
            && $user->can(AccessMatrix::PERMISSION_STOCK_MANAGE);
    }

    private function worksFor(User $user, Product $product): bool
    {
        return $user->belongsToCompany((int) $product->company_id);
    }
}
