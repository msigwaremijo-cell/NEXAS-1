<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Asset;
use App\Models\User;

/**
 * Who may read and keep a company's fixed-asset register.
 *
 * Three rights, and they are deliberately not one: `asset.view` reads the
 * register, `asset.manage` keeps it — the categories and the assets — and
 * `asset.post` charges depreciation and takes an asset out of the books. The
 * split is the same one the stock and document rights make, and for the same
 * reason: entering an asset is deciding what the company holds, while
 * depreciating and disposing of one move the ledger.
 *
 * Membership is checked by hand for the same reason as the other row-scoped
 * policies: a policy can be reached with an asset from a company the caller has
 * never been a member of, and a permission check alone would not notice.
 *
 * Laravel resolves this by convention (App\Policies\{Model}Policy).
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ASSET_VIEW);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->belongsToCompany((int) $asset->company_id)
            && $user->can(AccessMatrix::PERMISSION_ASSET_VIEW);
    }

    /**
     * Entering an asset into the register.
     *
     * A user-only ability because there is no row to scope: the category the
     * asset is entered against is resolved inside the caller's company before
     * this is reached, so membership is already settled.
     */
    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ASSET_MANAGE);
    }

    /**
     * Amending an asset already in the register.
     */
    public function update(User $user, Asset $asset): bool
    {
        return $user->belongsToCompany((int) $asset->company_id)
            && $user->can(AccessMatrix::PERMISSION_ASSET_MANAGE);
    }

    /**
     * Charging a period's depreciation across every asset in service.
     *
     * A user-only ability: the run is a company-wide act over the period named,
     * not an operation on one asset, so there is no row to scope. The period is
     * resolved inside the caller's company before this is reached.
     */
    public function depreciate(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ASSET_POST);
    }

    /**
     * Taking an asset out of the books for a proceeds amount.
     */
    public function dispose(User $user, Asset $asset): bool
    {
        return $user->belongsToCompany((int) $asset->company_id)
            && $user->can(AccessMatrix::PERMISSION_ASSET_POST);
    }

    /**
     * Undoing a disposal by reversing the journal it wrote.
     */
    public function reverseDisposal(User $user, Asset $asset): bool
    {
        return $user->belongsToCompany((int) $asset->company_id)
            && $user->can(AccessMatrix::PERMISSION_ASSET_POST);
    }
}
