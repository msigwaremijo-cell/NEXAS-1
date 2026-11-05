<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\LocalFinancialManagerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// The local-only Financial Manager seeder: the one account whose password never
// reaches the repository. It reads that password from the environment, so the
// tests write the environment directly rather than committing a secret.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The committed demo tenant only seeds the administrator now.
    $this->seed();
});

afterEach(function (): void {
    nxSetLocalPasswordEnv(null);
});

/**
 * Puts a value into every place Laravel's env() reads, or clears it.
 */
function nxSetLocalPasswordEnv(?string $password): void
{
    $key = 'NEXAS_FINANCE_MANAGER_PASSWORD';

    if ($password === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
        config(['nexas.local_finance_manager_password' => null]);

        return;
    }

    $_ENV[$key] = $password;
    $_SERVER[$key] = $password;
    putenv($key.'='.$password);
    config(['nexas.local_finance_manager_password' => $password]);
}

it('creates the Financial Manager from the environment password and grants the role', function () {
    nxSetLocalPasswordEnv('a-long-local-password-2026');

    app(LocalFinancialManagerSeeder::class)->run();

    $company = DB::table('companies')->sole();

    $row = DB::table('users')->where('email', LocalFinancialManagerSeeder::EMAIL)->sole();

    expect(Hash::check('a-long-local-password-2026', $row->password))->toBeTrue()
        ->and($row->status)->toBe('active')
        ->and(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $row->id)->exists())->toBeTrue();

    $member = User::query()->where('email', LocalFinancialManagerSeeder::EMAIL)->sole();

    setPermissionsTeamId($company->id);
    $hasRole = $member->hasRole(AccessMatrix::ROLE_FINANCIAL_MANAGER);
    setPermissionsTeamId(null);

    expect($hasRole)->toBeTrue();
});

it('refuses to run when the environment password is empty', function () {
    nxSetLocalPasswordEnv('');

    expect(fn () => app(LocalFinancialManagerSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'NEXAS_FINANCE_MANAGER_PASSWORD');
});

it('refuses the demo password', function () {
    nxSetLocalPasswordEnv('nexas2026');

    expect(fn () => app(LocalFinancialManagerSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'demo password');
});

it('refuses to run in production', function () {
    nxSetLocalPasswordEnv('a-long-local-password-2026');

    $this->app['env'] = 'production';

    expect(fn () => app(LocalFinancialManagerSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'production');
});
