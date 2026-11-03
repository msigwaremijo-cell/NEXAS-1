<?php

namespace Database\Seeders;

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The Financial Manager you sign in with on your own machine.
 *
 * This account is deliberately kept out of DemoUserSeeder and out of the
 * committed repository: its password is a local secret, read from the
 * environment rather than written down here, so no manager credential is ever
 * part of the history. Run it by hand after seeding the demo tenant:
 *
 *     NEXAS_FINANCE_MANAGER_PASSWORD='a-long-local-password' \
 *         php artisan db:seed --class=LocalFinancialManagerSeeder
 *
 * It refuses three ways on purpose. In production it refuses outright, because
 * a seeder that invents a privileged account from an environment value is a
 * back door on a server and belongs nowhere near real books. With no password
 * set it refuses rather than writing a blank one, and it refuses the demo
 * password too, so the account the whole system is built around cannot quietly
 * share the credential every reader of this repository already knows.
 */
class LocalFinancialManagerSeeder extends Seeder
{
    public const EMAIL = 'finance.manager@nexas.co.tz';

    /**
     * The password DemoUserSeeder writes for the administrator. Named here so
     * the guard below reads as the rule it is rather than a bare string.
     */
    private const DEMO_PASSWORD = 'nexas2026';

    /**
     * Where the local password is read from. Never committed: .env is ignored,
     * and only a commented placeholder reaches .env.example.
     */
    private const PASSWORD_ENV = 'NEXAS_FINANCE_MANAGER_PASSWORD';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'LocalFinancialManagerSeeder refuses to run in production: '
                .'create the Financial Manager through the application instead.'
            );
        }

        $password = trim((string) env(self::PASSWORD_ENV));

        if ($password === '') {
            throw new RuntimeException(
                'Set '.self::PASSWORD_ENV.' in your local .env before running this seeder.'
            );
        }

        if ($password === self::DEMO_PASSWORD) {
            throw new RuntimeException(
                self::PASSWORD_ENV.' must not be the demo password; choose a different local password.'
            );
        }

        $company = Company::query()
            ->where('name', CompanySeeder::DEMO_COMPANY_NAME)
            ->firstOrFail();

        $user = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Nexas Financial Manager',
                'phone' => '+255 700 000 001',
                'job_title' => 'Financial Manager',
                'password' => $password,
                'status' => 'active',
            ],
        );

        $user->companies()->syncWithoutDetaching([
            $company->id => ['is_default' => true, 'status' => 'active'],
        ]);

        setPermissionsTeamId($company->getKey());
        $user->syncRoles([AccessMatrix::ROLE_FINANCIAL_MANAGER]);
        setPermissionsTeamId(null);
    }
}
