<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Exceptions\AccessStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives a member one more role in a company.
 *
 * Roles are additive, because the jobs they stand for are: a member who keeps
 * the stock books may also be the person who signs off a supplier bill, and
 * forcing one role per member would mean inventing a combined role for every
 * such pairing. What the pairing must not lose is the separation the matrix
 * already expresses — the Finance Officer who may approve a bill is still the
 * person who may not write one — so adding a role adds its grants and takes
 * none away.
 *
 * Assigning a role the member already holds is a no-op rather than an error:
 * the state asked for is the state that results, and a screen that retries an
 * assignment should not be told it failed.
 */
final class AssignMemberRole
{
    use WorksWithCompanyRoles;

    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(User $member, int $companyId, string $role): User
    {
        return DB::transaction(function () use ($member, $companyId, $role): User {
            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $before = $this->rolesInCompany($locked, $companyId);

            if (in_array($role, $before, true)) {
                return $locked;
            }

            $this->inCompany($companyId, fn () => $locked->assignRole($role));

            // Re-read from a fresh copy: the roles relation Spatie caches on
            // the model is what was just written, and the audit must record
            // what is really in the table rather than what was asked for.
            $after = $this->rolesInCompany($locked->fresh() ?? $locked, $companyId);

            $this->audit->record('user.role.assigned', $locked, [
                'roles' => $before,
            ], [
                'roles' => $after,
            ], $companyId);

            return $locked;
        });
    }
}
