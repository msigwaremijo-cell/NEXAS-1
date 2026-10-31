<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\AssetCategory;
use App\Models\User;

/**
 * Who may read and keep the categories that decide where a class of assets
 * posts.
 *
 * A category names the accounts a whole class of assets is carried in, charged
 * against and written out of, so writing one is a decision about the books
 * rather than a posting: `asset.manage` guards it, and `asset.view` reads it.
 * Reading the register without being able to read the categories it is built
 * from would leave half of it unexplained.
 *
 * Membership is checked by hand for the same reason as the other row-scoped
 * policies: a policy can be reached with a category from a company the caller
 * has never been a member of, and a permission check alone would not notice.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy).
 */
class AssetCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ASSET_VIEW);
    }

    public function view(User $user, AssetCategory $category): bool
    {
        return $user->belongsToCompany((int) $category->company_id)
            && $user->can(AccessMatrix::PERMISSION_ASSET_VIEW);
    }

    /**
     * Writing a category for the first time.
     *
     * A user-only ability because there is no row to scope: the accounts it
     * names are resolved inside the caller's company before this is reached, so
     * membership is already settled.
     */
    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ASSET_MANAGE);
    }

    /**
     * Amending a category already in use.
     */
    public function update(User $user, AssetCategory $category): bool
    {
        return $user->belongsToCompany((int) $category->company_id)
            && $user->can(AccessMatrix::PERMISSION_ASSET_MANAGE);
    }
}
