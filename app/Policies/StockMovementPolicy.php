<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Who may move stock, and for which company.
 *
 * Reading the movement history and writing a new movement are deliberately
 * separate rights: the Auditor may read every movement and post none. There is
 * no update and no delete policy, because a posted movement is never edited or
 * removed — the only write is a reversal, which is a new movement and so is
 * covered by the same `create` right that lets a movement be posted at all.
 */
class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_STOCK_VIEW);
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->worksFor($user, $movement)
            && $user->can(AccessMatrix::PERMISSION_STOCK_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_STOCK_POST);
    }

    /**
     * Reversing a movement is a new movement of the opposite type, so it asks
     * for the same right as posting one. It is spelled out as its own ability
     * rather than left to `create` so the route names what it is doing.
     */
    public function reverse(User $user, StockMovement $movement): bool
    {
        return $this->worksFor($user, $movement)
            && $user->can(AccessMatrix::PERMISSION_STOCK_POST);
    }

    private function worksFor(User $user, StockMovement $movement): bool
    {
        return $user->belongsToCompany((int) $movement->company_id);
    }
}
