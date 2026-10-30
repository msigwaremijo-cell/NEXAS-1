<?php

namespace App\Domain\Access\Actions;

use App\Models\User;

/**
 * Reads and writes a member's roles in one company.
 *
 * config/permission.php runs Spatie in teams mode keyed on company_id, so both
 * a role write and a role read only answer for the right company while the
 * resolver holds that company. During an API request the `company` middleware
 * has already pinned it, but an action is also reachable from a console command
 * or a test, where nothing has: pinning here means the action answers the same
 * way whoever calls it.
 *
 * The resolver is put back the way it was found rather than cleared, because
 * the caller is usually mid-request and still has roles to read afterwards.
 */
trait WorksWithCompanyRoles
{
    private function inCompany(int $companyId, callable $callback): mixed
    {
        $previous = getPermissionsTeamId();

        setPermissionsTeamId($companyId);

        try {
            return $callback();
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    /**
     * The roles a member holds in one company, sorted so that two reads of the
     * same state cannot differ merely by the order the database returned them
     * in. That matters because the answer is written to the audit chain, where
     * a reordering would read as a change.
     *
     * @return list<string>
     */
    private function rolesInCompany(User $member, int $companyId): array
    {
        return $this->inCompany(
            $companyId,
            fn (): array => $member->getRoleNames()->sort()->values()->all(),
        );
    }
}
