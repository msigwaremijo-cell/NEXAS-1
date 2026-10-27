<?php

namespace Database\Seeders;

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the permissions once, then the four business roles once per company.
 *
 * Permissions are global; only roles carry a company_id. That is why the team
 * id is set before every role lookup: Role::findOrCreate() copies whatever
 * getPermissionsTeamId() returns into the company_id column, so forgetting to
 * set it would seed roles that belong to no company at all and that no member
 * could ever be granted.
 *
 * Everything here is idempotent: rerunning the seeder reconciles the roles to
 * the matrix rather than adding a second copy of them.
 */
class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Taken from the model rather than from config('auth.defaults.guard'):
        // that default is rewritten to the authenticating guard on API requests,
        // and the seeder must write roles under the same guard the request will
        // later look them up under.
        $guard = Guard::getDefaultName(User::class);
        $registrar = app(PermissionRegistrar::class);

        foreach (AccessMatrix::permissions() as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        $registrar->forgetCachedPermissions();

        Company::query()->orderBy('id')->each(function (Company $company) use ($guard, $registrar): void {
            setPermissionsTeamId($company->getKey());

            foreach (AccessMatrix::grants() as $roleName => $permissions) {
                Role::findOrCreate($roleName, $guard)->syncPermissions($permissions);
            }

            $registrar->forgetCachedPermissions();
        });

        // Leave no company pinned on the resolver: the next thing to run is
        // either a request (which sets its own) or another seeder.
        setPermissionsTeamId(null);

        $registrar->forgetCachedPermissions();
    }
}
