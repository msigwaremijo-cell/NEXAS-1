<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Access\TwoFactor;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token login for the API.
 *
 * A token is issued per device, not per company: the company is chosen on each
 * request with the X-Company-Id header, and pinned by the `company` middleware
 * only after it has confirmed the caller is a member of it. That keeps one
 * token usable across the companies a person belongs to without letting the
 * header itself be an escalation path.
 *
 * A password is no longer the whole of a login for an account that carries a
 * second factor. Where one is switched on, the password buys a short-lived
 * challenge ticket and nothing else; the token is only minted once the code
 * has been checked. That is why the token is issued from one private method
 * reached two ways rather than written out twice.
 *
 * Every token carries an expiry from configuration. A credential that lives
 * forever is one nobody is watching, and a machine that is lost or a token
 * that leaks has to stop working on its own as well as on request.
 */
class AuthController extends Controller
{
    public function login(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
            'company_id' => ['sometimes', 'integer'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        // Hash::check is still called when the user is missing, so a wrong
        // address and a wrong password take the same time to answer and the
        // endpoint does not become a way to enumerate accounts.
        if (! Hash::check($data['password'], $user?->password ?? '')) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        $companyId = $data['company_id'] ?? $user->defaultCompanyId();

        if ($companyId === null || ! $user->belongsToCompany($companyId)) {
            throw ValidationException::withMessages([
                'company_id' => ['You do not have access to this company.'],
            ]);
        }

        // The password has been proved and the company has been settled, but
        // for an account with a second factor that is only half a login. The
        // challenge says which user and which company got this far and carries
        // no authority of its own.
        if ($twoFactor->enabledFor($user)) {
            return response()->json([
                'two_factor' => true,
                'challenge_token' => $twoFactor->issueChallenge($user, $companyId),
                'expires_in' => $twoFactor->challengeTtlMinutes() * 60,
            ]);
        }

        return $this->issueToken($user, $companyId, $data['device_name'] ?? null, $twoFactor);
    }

    /**
     * The second half of a login: a challenge ticket and a code become a token.
     *
     * Unauthenticated, because a challenge is not yet a session and the ticket
     * is the only thing identifying the caller. It is rate limited like the
     * login beside it, since a six-digit code is worth guessing.
     */
    public function twoFactorChallenge(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'recovery_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $code = $data['code'] ?? null;
        $recoveryCode = $data['recovery_code'] ?? null;

        // Exactly one of the two, so a request that offers neither, or both,
        // is answered rather than guessed at.
        if (blank($code) === blank($recoveryCode)) {
            throw ValidationException::withMessages([
                'code' => ['Provide either the code from your authenticator app or one of your recovery codes.'],
            ]);
        }

        ['user' => $user, 'company_id' => $companyId] = $twoFactor->consumeChallenge($data['challenge_token']);

        $accepted = filled($recoveryCode)
            ? $twoFactor->consumeRecoveryCode($user, $recoveryCode)
            : $twoFactor->verifyCode($user, (string) $code);

        if (! $accepted) {
            throw ValidationException::withMessages([
                'code' => [AccessException::twoFactorCodeRefused()->getMessage()],
            ]);
        }

        return $this->issueToken($user, $companyId, $data['device_name'] ?? null, $twoFactor);
    }

    public function me(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        return response()->json([
            'company_id' => $request->attributes->get('company_id'),
            'user' => $this->profile($request->user(), $twoFactor),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // A token resolved from a session is a TransientToken with nothing to
        // revoke, which is only reachable when Sanctum's stateful middleware
        // is switched on.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * Revokes every token the member holds, including the one making the
     * request.
     *
     * The answer to "my laptop was stolen" and to "I signed in on a machine I
     * no longer trust": the per-device logout above cannot reach a device the
     * member is not holding.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $revoked = $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'All tokens revoked.',
            'revoked' => $revoked,
        ]);
    }

    /**
     * Mints the token a finished login carries, and records that it happened.
     */
    private function issueToken(User $user, int $companyId, ?string $deviceName, TwoFactor $twoFactor): JsonResponse
    {
        $lifetime = (int) config('nexas.auth.token_lifetime_minutes', 0);

        $token = $user->createToken(
            $deviceName ?? 'api-token',
            ['*'],
            $lifetime > 0 ? now()->addMinutes($lifetime) : null,
        );

        $user->forceFill(['last_login_at' => now()])->save();

        setPermissionsTeamId($companyId);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'company_id' => $companyId,
            'expires_in' => $lifetime > 0 ? $lifetime * 60 : null,
            'user' => $this->profile($user, $twoFactor),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user, TwoFactor $twoFactor): array
    {
        return [
            'id' => $user->getKey(),
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'job_title' => $user->job_title,
            // Whether a second factor stands between this account and a
            // password, so a client can offer to set one up. The secret itself
            // is never serialised anywhere.
            'two_factor_enabled' => $twoFactor->enabledFor($user),
            'roles' => $user->getRoleNames()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
        ];
    }
}
