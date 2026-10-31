<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Exceptions\AccessStateException;
use App\Domain\Access\TwoFactor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The second factor on the caller's own account.
 *
 * Self-service rather than administration: the account being changed is the
 * one making the request, and the right it is behind is the password the
 * caller just typed rather than a permission in the matrix. That is deliberate
 * — gating this on `user.manage` would mean only an Owner could protect their
 * own account, which is backwards.
 *
 * Every method here re-proves the password. A bearer token on its own is not
 * enough to take a second factor off an account: if the token has leaked, the
 * whole point of the factor is that it cannot be removed with the token alone.
 *
 * Nothing here is ever audited with the secret in it. The trail records that
 * the factor was switched on, off or re-coded, and never the material.
 */
class TwoFactorController extends Controller
{
    /**
     * Begins an enrolment and hands back what the authenticator app scans.
     *
     * The factor is not live until it is confirmed with a code: a client that
     * walks away at this point has left the account exactly as open as it
     * found it.
     */
    public function enable(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $this->confirmingPassword($request);

        $user = $request->user();

        if ($twoFactor->enabledFor($user)) {
            throw AccessStateException::twoFactorAlreadyEnabled();
        }

        $enrolment = $twoFactor->begin($user);

        return response()->json([
            'data' => [
                'secret' => $enrolment['secret'],
                'provisioning_uri' => $enrolment['provisioning_uri'],
                'confirmed' => false,
            ],
        ]);
    }

    /**
     * Turns the factor on, and shows the recovery codes once.
     */
    public function confirm(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ]);

        $codes = $twoFactor->confirm($request->user(), $data['code'], $this->companyId($request));

        return response()->json([
            'data' => [
                'confirmed' => true,
                'recovery_codes' => $codes,
            ],
        ]);
    }

    /**
     * Switches the factor off.
     */
    public function disable(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $this->confirmingPassword($request);

        $twoFactor->disable($request->user(), $this->companyId($request));

        return response()->json(['message' => 'Two-factor authentication switched off.']);
    }

    /**
     * Issues a fresh set of recovery codes, invalidating the old ones.
     */
    public function regenerateRecoveryCodes(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $this->confirmingPassword($request);

        $codes = $twoFactor->regenerateRecoveryCodes($request->user(), $this->companyId($request));

        return response()->json([
            'data' => [
                'recovery_codes' => $codes,
            ],
        ]);
    }

    /**
     * A change to the second factor is a change to how this account is
     * reached, so the password is re-typed every time. Refused rather than
     * redirected, because the caller here is an API client.
     */
    private function confirmingPassword(Request $request): void
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], (string) $request->user()?->password)) {
            throw ValidationException::withMessages([
                'password' => [__('auth.password')],
            ]);
        }
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
