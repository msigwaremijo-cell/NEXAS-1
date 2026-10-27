<?php

namespace Database\Seeders;

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One operator to sign in with while the application is being built.
 *
 * The administrator is the Owner of the demo company. Granting a role means
 * naming the company it applies to, so the team id is set before the role is
 * assigned: otherwise Spatie would look for the role under a null team and
 * quietly attach nothing.
 */
class DemoUserSeeder extends Seeder
{
    public const DEMO_EMAIL = 'admin@nexas.co.tz';

    public const DEMO_PASSWORD = 'nexas2026';

    public function run(): void
    {
        $company = Company::query()
            ->where('name', CompanySeeder::DEMO_COMPANY_NAME)
            ->firstOrFail();

        $user = User::updateOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'name' => 'Nexas Administrator',
                'password' => self::DEMO_PASSWORD,
                'phone' => '+255 700 000 000',
                'job_title' => 'System Administrator',
                'status' => 'active',
            ]
        );

        $user->companies()->syncWithoutDetaching([
            $company->id => ['is_default' => true, 'status' => 'active'],
        ]);

        setPermissionsTeamId($company->getKey());
        $user->syncRoles([AccessMatrix::ROLE_OWNER]);
        setPermissionsTeamId(null);
    }
}
