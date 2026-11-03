<?php

namespace Database\Seeders;

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The operator to sign in with while the application is being built.
 *
 * One account, because there is one credential this repository may carry: the
 * administrator, who is the Owner of the demo company and reaches the main
 * application. The Financial Manager is deliberately absent — that account's
 * password is a local secret and is created by LocalFinancialManagerSeeder
 * from the environment, so no manager credential is ever committed here.
 *
 * Granting a role means naming the company it applies to, so the team id is set
 * before the role is assigned: otherwise Spatie would look for the role under a
 * null team and quietly attach nothing.
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

        $this->member($company, AccessMatrix::ROLE_OWNER, [
            'email' => self::DEMO_EMAIL,
            'name' => 'Nexas Administrator',
            'phone' => '+255 700 000 000',
            'job_title' => 'System Administrator',
        ]);
    }

    /**
     * Creates or updates one demo member and gives them their role in the demo
     * company, settling the team first so the role lands on the right company.
     *
     * @param  array<string, string>  $attributes
     */
    private function member(Company $company, string $role, array $attributes): User
    {
        $user = User::updateOrCreate(
            ['email' => $attributes['email']],
            $attributes + [
                'password' => self::DEMO_PASSWORD,
                'status' => 'active',
            ]
        );

        $user->companies()->syncWithoutDetaching([
            $company->id => ['is_default' => true, 'status' => 'active'],
        ]);

        setPermissionsTeamId($company->getKey());
        $user->syncRoles([$role]);
        setPermissionsTeamId(null);

        return $user;
    }
}
