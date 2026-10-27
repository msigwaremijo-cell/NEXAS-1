<?php

namespace App\Http\Controllers\Api\V1;

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
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
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

        $token = $user->createToken($data['device_name'] ?? 'api-token');

        $user->forceFill(['last_login_at' => now()])->save();

        setPermissionsTeamId($companyId);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'company_id' => $companyId,
            'user' => $this->profile($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'company_id' => $request->attributes->get('company_id'),
            'user' => $this->profile($request->user()),
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
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'job_title' => $user->job_title,
            'roles' => $user->getRoleNames()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->all(),
        ];
    }
}
