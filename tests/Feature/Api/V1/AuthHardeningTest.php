<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Totp;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Authentication over real HTTP and real MySQL, because what is under test
// includes a rate limiter backed by the cache, a token row whose expiry is
// read back as a date, and two columns that are encrypted on the way in.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company with a known password, holding one company-scoped
 * role.
 */
function nxSecureMember(int $companyId, string $role = AccessMatrix::ROLE_OWNER, string $password = 'secret-key-2026'): User
{
    $user = User::factory()->create(['password' => $password]);

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A member who has already enrolled a second factor, with the secret and the
 * recovery codes they were shown.
 *
 * @return array{user: User, secret: string, recovery_codes: list<string>}
 */
function nxEnrolled(int $companyId, string $password = 'secret-key-2026'): array
{
    $user = nxSecureMember($companyId, AccessMatrix::ROLE_OWNER, $password);

    Sanctum::actingAs($user);

    $enrolment = test()->postJson('/api/v1/auth/two-factor/enable', ['password' => $password])
        ->assertOk()
        ->json('data');

    $recoveryCodes = test()->postJson('/api/v1/auth/two-factor/confirm', [
        'code' => app(Totp::class)->code($enrolment['secret']),
    ])->assertOk()->json('data.recovery_codes');

    Auth::forgetGuards();

    return ['user' => $user, 'secret' => $enrolment['secret'], 'recovery_codes' => $recoveryCodes];
}

/*
|--------------------------------------------------------------------------
| Login throttling
|--------------------------------------------------------------------------
*/

it('rate limits repeated failed logins for the same account', function (): void {
    config(['nexas.auth.login_max_attempts' => 3, 'nexas.auth.login_decay_seconds' => 60]);

    $user = nxSecureMember($this->companyId);

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    // The fourth attempt is refused before the password is even looked at, so
    // even the right one is turned away until the window closes.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ])->assertStatus(429);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('counts the login limit per account rather than per connection', function (): void {
    config(['nexas.auth.login_max_attempts' => 2, 'nexas.auth.login_decay_seconds' => 60]);

    $exhausted = nxSecureMember($this->companyId);
    $innocent = nxSecureMember($this->companyId);

    foreach (range(1, 2) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $exhausted->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $exhausted->email,
        'password' => 'secret-key-2026',
    ])->assertStatus(429);

    // A second account signing in from the same address is untouched, which is
    // the whole point of keying on the pair rather than on the address alone.
    $this->postJson('/api/v1/auth/login', [
        'email' => $innocent->email,
        'password' => 'secret-key-2026',
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| Password policy
|--------------------------------------------------------------------------
*/

it('refuses an invited password the policy does not accept', function (string $password): void {
    Sanctum::actingAs(nxSecureMember($this->companyId));

    $this->postJson('/api/v1/users', [
        'name' => 'Invited Member',
        'email' => 'invited@nexas.co.tz',
        'password' => $password,
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->assertDatabaseMissing('users', ['email' => 'invited@nexas.co.tz']);
})->with([
    'too short' => 'Sh0rt-pw',
    'letters only' => 'alphabeticalonly',
    'no symbol' => 'Password12345',
]);

it('accepts an invited password the policy does accept', function (): void {
    Sanctum::actingAs(nxSecureMember($this->companyId));

    $this->postJson('/api/v1/users', [
        'name' => 'Invited Member',
        'email' => 'invited@nexas.co.tz',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'invited@nexas.co.tz']);
});

/*
|--------------------------------------------------------------------------
| Token lifetime and revocation
|--------------------------------------------------------------------------
*/

it('issues a token with an expiry, and refuses it once that expiry passes', function (): void {
    config(['nexas.auth.token_lifetime_minutes' => 60]);

    $user = nxSecureMember($this->companyId);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
        'device_name' => 'pest',
    ])->assertOk()
        ->assertJsonPath('expires_in', 3600)
        ->json('token');

    expect(DB::table('personal_access_tokens')->value('expires_at'))->not->toBeNull();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->travel(61)->minutes();

    // A guard instance lives as long as the container, so it has to be
    // discarded to ask whether the token still authenticates at all.
    Auth::forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('revokes every token the member holds, not just the one asking', function (): void {
    $user = nxSecureMember($this->companyId);

    $laptop = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
        'device_name' => 'laptop',
    ])->assertOk()->json('token');

    $phone = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
        'device_name' => 'phone',
    ])->assertOk()->json('token');

    expect(DB::table('personal_access_tokens')->count())->toBe(2);

    $this->withToken($laptop)->postJson('/api/v1/auth/logout-all')
        ->assertOk()
        ->assertJsonPath('revoked', 2);

    $this->assertDatabaseCount('personal_access_tokens', 0);

    Auth::forgetGuards();

    $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Two-factor enrolment
|--------------------------------------------------------------------------
*/

it('enrols a second factor and requires a code to finish the next login', function (): void {
    $user = nxSecureMember($this->companyId);

    Sanctum::actingAs($user);

    $enrolment = $this->postJson('/api/v1/auth/two-factor/enable', ['password' => 'secret-key-2026'])
        ->assertOk()
        ->assertJsonPath('data.confirmed', false)
        ->json('data');

    expect($enrolment['secret'])->toMatch('/^[A-Z2-7]{32}$/')
        ->and($enrolment['provisioning_uri'])->toStartWith('otpauth://totp/');

    $totp = app(Totp::class);

    $codes = $this->postJson('/api/v1/auth/two-factor/confirm', [
        'code' => $totp->code($enrolment['secret']),
    ])->assertOk()->assertJsonPath('data.confirmed', true)->json('data.recovery_codes');

    expect($codes)->toHaveCount(8);

    // What the database holds is not what the client was shown: the secret is
    // stored through an encrypted cast, so a dump of the users table is not a
    // list of working second factors.
    expect(DB::table('users')->where('id', $user->getKey())->value('two_factor_secret'))
        ->not->toBe($enrolment['secret'])
        ->not->toBeNull();

    Auth::forgetGuards();

    // The password alone no longer buys a token, only a challenge.
    $half = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ])->assertOk()
        ->assertJsonPath('two_factor', true)
        ->assertJsonMissingPath('token');

    expect(DB::table('personal_access_tokens')->count())->toBe(0);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $half->json('challenge_token'),
        'code' => $totp->code($enrolment['secret']),
        'device_name' => 'pest',
    ])->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('company_id', $this->companyId);

    expect(DB::table('personal_access_tokens')->count())->toBe(1);
});

it('never shows the secret back once it has been handed out', function (): void {
    ['user' => $user] = nxEnrolled($this->companyId);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.two_factor_enabled', true)
        ->assertJsonMissingPath('user.two_factor_secret')
        ->assertJsonMissingPath('user.two_factor_recovery_codes');
});

it('refuses to enrol a second factor twice', function (): void {
    ['user' => $user] = nxEnrolled($this->companyId);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/two-factor/enable', ['password' => 'secret-key-2026'])
        ->assertStatus(409);
});

it('refuses to start an enrolment without the password', function (): void {
    $user = nxSecureMember($this->companyId);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/two-factor/enable', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    $this->postJson('/api/v1/auth/two-factor/enable', ['password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(DB::table('users')->where('id', $user->getKey())->value('two_factor_secret'))->toBeNull();
});

it('refuses to confirm an enrolment that was never started', function (): void {
    Sanctum::actingAs(nxSecureMember($this->companyId));

    $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Completing a challenge
|--------------------------------------------------------------------------
*/

it('refuses a wrong one-time code', function (): void {
    ['user' => $user, 'secret' => $secret] = nxEnrolled($this->companyId);

    Auth::forgetGuards();

    $half = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ]);

    $wrong = app(Totp::class)->code($secret) === '000000' ? '111111' : '000000';

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $half->json('challenge_token'),
        'code' => $wrong,
    ])->assertStatus(422)->assertJsonValidationErrors('code');

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('refuses a request that offers neither a code nor a recovery code', function (): void {
    ['user' => $user] = nxEnrolled($this->companyId);

    Auth::forgetGuards();

    $half = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ]);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $half->json('challenge_token'),
    ])->assertStatus(422)->assertJsonValidationErrors('code');
});

it('refuses a tampered challenge ticket', function (): void {
    ['user' => $user] = nxEnrolled($this->companyId);

    Auth::forgetGuards();

    $half = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ]);

    $ticket = $half->json('challenge_token');

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => substr($ticket, 0, -4).'AAAA',
        'code' => '123456',
    ])->assertStatus(422);
});

it('refuses a challenge ticket once its window has passed', function (): void {
    ['user' => $user, 'secret' => $secret] = nxEnrolled($this->companyId);

    Auth::forgetGuards();

    $ticket = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ])->json('challenge_token');

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $ticket,
        'code' => app(Totp::class)->code($secret),
    ])->assertStatus(422);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

/*
|--------------------------------------------------------------------------
| Recovery codes
|--------------------------------------------------------------------------
*/

it('accepts a recovery code once and refuses it the second time', function (): void {
    ['user' => $user, 'recovery_codes' => $codes] = nxEnrolled($this->companyId);

    Auth::forgetGuards();

    $ticket = fn (): string => $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ])->json('challenge_token');

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $ticket(),
        'recovery_code' => $codes[0],
    ])->assertOk()->assertJsonPath('token_type', 'Bearer');

    expect(DB::table('personal_access_tokens')->count())->toBe(1);

    // The code that just worked is spent, so a replay of the same value is
    // refused even though the account still holds seven others.
    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $ticket(),
        'recovery_code' => $codes[0],
    ])->assertStatus(422)->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $ticket(),
        'recovery_code' => $codes[1],
    ])->assertOk();

    expect(DB::table('personal_access_tokens')->count())->toBe(2);
});

it('replaces the recovery codes on request, invalidating the old ones', function (): void {
    ['user' => $user, 'recovery_codes' => $codes] = nxEnrolled($this->companyId);

    Sanctum::actingAs($user);

    $fresh = $this->postJson('/api/v1/auth/two-factor/recovery-codes', ['password' => 'secret-key-2026'])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($fresh)->toHaveCount(8)
        ->and(array_intersect($fresh, $codes))->toBe([]);

    Auth::forgetGuards();

    $ticket = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ])->json('challenge_token');

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $ticket,
        'recovery_code' => $codes[0],
    ])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Switching the factor off
|--------------------------------------------------------------------------
*/

it('refuses to switch the second factor off without the password', function (): void {
    ['user' => $user] = nxEnrolled($this->companyId);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'not-the-password'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    $this->assertDatabaseMissing('users', [
        'id' => $user->getKey(),
        'two_factor_confirmed_at' => null,
    ]);
});

it('switches the second factor off and lets the password alone back in', function (): void {
    ['user' => $user] = nxEnrolled($this->companyId);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'secret-key-2026'])
        ->assertOk();

    expect(DB::table('users')->where('id', $user->getKey())->value('two_factor_secret'))->toBeNull()
        ->and(DB::table('users')->where('id', $user->getKey())->value('two_factor_confirmed_at'))->toBeNull();

    Auth::forgetGuards();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-key-2026',
    ])->assertOk()
        ->assertJsonMissingPath('two_factor')
        ->assertJsonPath('token_type', 'Bearer');
});

it('refuses to switch off a second factor that is not switched on', function (): void {
    Sanctum::actingAs(nxSecureMember($this->companyId));

    $this->postJson('/api/v1/auth/two-factor/disable', ['password' => 'secret-key-2026'])
        ->assertStatus(409);
});
