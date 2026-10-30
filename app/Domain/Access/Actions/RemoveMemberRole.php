<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Access\Exceptions\AccessStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Takes one role away from a member of a company.
 *
 * The removal is refused in two cases, and both are about what the company
 * would be left with rather than about the request:
 *
 * - The member does not hold the role, so there is nothing to remove. Silently
 *   answering "done" would let a screen believe a right was withdrawn when it
 *   never was.
 * - The role is the last Owner in the company. The Owner is the only role that
 *   may manage members and the only one that may close a year, so losing the
 *   last one is a state no API call can undo. It is checked by counting the
 *   company's Owners rather than by looking at this member alone, because
 *   whether the removal is safe is a fact about the team.
 */
final class RemoveMemberRole
{
    use WorksWithCompanyRoles;

    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(User $member, int $companyId, string $role): User
    {
        return DB::transaction(function () use ($member, $companyId, $role): User {
            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $before = $this->rolesInCompany($locked, $companyId);

            if (! in_array($role, $before, true)) {
                throw AccessException::roleNotHeld($locked, $role);
            }

            if ($role === AccessMatrix::ROLE_OWNER && $this->ownersIn($companyId) <= 1) {
                throw AccessStateException::lastOwnerRefused($locked, $role);
            }

            $this->inCompany($companyId, fn () => $locked->removeRole($role));

            $after = $this->rolesInCompany($locked->fresh() ?? $locked, $companyId);

            $this->audit->record('user.role.removed', $locked, [
                'roles' => $before,
            ], [
                'roles' => $after,
            ], $companyId);

            return $locked;
        });
    }

    /**
     * How many members hold Owner in this company.
     *
     * Counted from the pivot rather than through the model, because Spatie's
     * `role` scope resolves a role per team and a count across the company's
     * members is exactly what is being asked. A member holding Owner twice
     * cannot exist — the pivot is keyed on the triple — so a distinct count of
     * member ids is the number of Owners.
     */
    private function ownersIn(int $companyId): int
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.company_id', $companyId)
            ->where('roles.company_id', $companyId)
            ->where('roles.name', AccessMatrix::ROLE_OWNER)
            ->distinct()
            ->count('model_has_roles.model_id');
    }
}
