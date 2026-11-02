<?php

namespace Database\Seeders;

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The operators to sign in with while the application is being built.
 *
 * Two of them, because the system now has two doors: the administrator, who is
 * the Owner of the demo company and reaches the main application, and the
 * financial manager, who is the only account that may sign in at the separate
 * portal and decide the approvals queue. Keeping both here means the two
 * credentials live in one place rather than one per portal.
 *
 * Granting a role means naming the company it applies to, so the team id is set
 * before the role is assigned: otherwise Spatie would look for the role under a
 * null team and quietly attach nothing.
 */
class DemoUserSeeder extends Seeder
{
    public const DEMO_EMAIL = 'admin@nexas.co.tz';

    public const DEMO_PASSWORD = 'nexas2026';

    public const FINANCIAL_MANAGER_EMAIL = 'finance.manager@nexas.co.tz';

    public const FINANCIAL_MANAGER_PASSWORD = 'nexas2026';

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

        $this->member($company, AccessMatrix::ROLE_FINANCIAL_MANAGER, [
            'email' => self::FINANCIAL_MANAGER_EMAIL,
            'name' => 'Nexas Financial Manager',
            'phone' => '+255 700 000 001',
            'job_title' => 'Financial Manager',
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
