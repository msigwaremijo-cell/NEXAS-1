<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccountingFixtures;

// The sign-in page and the login endpoint behind it, over real HTTP and real
// MySQL: what is under test includes a token whose expiry is read back as a
// date, a rate limiter backed by the cache, and the role a destination is
// chosen from.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company with a known password, holding one company-scoped
 * role. Named apart from the helpers in the neighbouring auth suites so the
 * two files can load in the same process.
 */
function nxWebMember(int $companyId, string $role, string $password = 'secret-key-2026'): User
{
    $user = User::factory()->create(['password' => $password]);

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/*
|--------------------------------------------------------------------------
| The sign-in page
|--------------------------------------------------------------------------
*/

it('serves a sign-in page that posts to the API and no longer claims there is no backend', function () {
    $page = $this->get('/')->assertOk();

    // The form now talks to the real endpoint, keeps its token in
    // sessionStorage, and has stopped calling itself a preview with no backend.
    $page->assertSee('/api/v1/auth/login')
        ->assertSee("token: 'nexas.fm.token'", false)
        ->assertSee('sessionStorage.setItem')
        ->assertDontSee('No backend connected.')
        ->assertDontSee('No authentication backend is connected yet');
});

it('serves the placeholder a member lands on when their role has no portal yet', function () {
    $this->get('/coming-soon')
        ->assertOk()
        ->assertSee('Your workspace is being built');
});

/*
|--------------------------------------------------------------------------
| Redirect by role
|--------------------------------------------------------------------------
*/

it('sends a Financial Manager to the portal and every other role to the placeholder', function () {
    $manager = nxWebMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);
    $accountant = nxWebMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    $this->postJson('/api/v1/auth/login', [
        'email' => $manager->email,
        'password' => 'secret-key-2026',
    ])
        ->assertOk()
        ->assertJsonPath('redirect_to', '/financial-manager')
        ->assertJsonPath('user.roles', [AccessMatrix::ROLE_FINANCIAL_MANAGER]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $accountant->email,
        'password' => 'secret-key-2026',
    ])
        ->assertOk()
        ->assertJsonPath('redirect_to', '/coming-soon')
        ->assertJsonPath('user.roles', [AccessMatrix::ROLE_ACCOUNTANT]);
});

/*
|--------------------------------------------------------------------------
| A wrong password, and the throttle
|--------------------------------------------------------------------------
*/

it('refuses a wrong password and an unknown account with one and the same answer', function () {
    $member = nxWebMember($this->companyId, AccessMatrix::ROLE_OWNER);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => $member->email,
        'password' => 'not-the-password',
    ])->assertStatus(422)->assertJsonMissingPath('token');

    $unknownAccount = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@nexas.co.tz',
        'password' => 'not-the-password',
    ])->assertStatus(422)->assertJsonMissingPath('token');

    // Neither the field nor the wording tells the caller which of the two it
    // was, so the endpoint is not a way to find out which addresses exist.
    expect($wrongPassword->json('errors.email'))->toBe($unknownAccount->json('errors.email'));

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('throttles repeated failed sign-ins before the password is even looked at', function () {
    config(['nexas.auth.login_max_attempts' => 3, 'nexas.auth.login_decay_seconds' => 60]);

    $member = nxWebMember($this->companyId, AccessMatrix::ROLE_OWNER);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $member->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    // The fourth attempt is refused before the password is compared, so even
    // the right one is turned away until the window closes.
    $this->postJson('/api/v1/auth/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])->assertStatus(429);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

/*
|--------------------------------------------------------------------------
| Remember me
|--------------------------------------------------------------------------
*/

it('gives a remembered sign-in the longer lifetime and an ordinary one the shorter', function () {
    config([
        'nexas.auth.token_lifetime_minutes' => 60,
        'nexas.auth.remember_token_lifetime_minutes' => 180,
    ]);

    $member = nxWebMember($this->companyId, AccessMatrix::ROLE_OWNER);

    $plain = $this->postJson('/api/v1/auth/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])->assertOk();

    $remembered = $this->postJson('/api/v1/auth/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
        'remember' => true,
    ])->assertOk();

    expect($plain->json('expires_in'))->toBe(60 * 60)
        ->and($remembered->json('expires_in'))->toBe(180 * 60);

    // The longer window is the one actually written to the token row, not only
    // what the response says: 180 minutes for the remembered token against 60
    // for the ordinary one, a difference of two hours.
    $this->assertDatabaseCount('personal_access_tokens', 2);

    $expiries = DB::table('personal_access_tokens')->orderBy('id')->pluck('expires_at');

    $differenceInMinutes = (strtotime((string) $expiries[1]) - strtotime((string) $expiries[0])) / 60;

    expect($differenceInMinutes)->toBeGreaterThan(115)->toBeLessThan(125);
});

/*
|--------------------------------------------------------------------------
| Sign out
|--------------------------------------------------------------------------
*/

it('revokes the token when the member signs out', function () {
    $member = nxWebMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $member->email,
        'password' => 'secret-key-2026',
    ])->assertOk()->json('token');

    $headers = [
        'Authorization' => 'Bearer '.$token,
        'X-Company-Id' => (string) $this->companyId,
    ];

    $this->withHeaders($headers)->postJson('/api/v1/auth/logout')->assertOk();

    $this->assertDatabaseCount('personal_access_tokens', 0);

    // The token no longer identifies anyone, so the stored copy is worthless.
    // The guard is forgotten first, because within one test the container keeps
    // the user it resolved from the previous request rather than reading the
    // token again.
    Auth::forgetGuards();

    $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertUnauthorized();
});
