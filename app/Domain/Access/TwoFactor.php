<?php

namespace App\Domain\Access;

use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Access\Exceptions\AccessStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

/**
 * The second factor on a member's own account.
 *
 * Two things turn a password into a login here, and this class owns both: the
 * shared secret an authenticator app is enrolled with, and the short-lived
 * ticket that carries a password-only sign-in across the gap until the code
 * arrives.
 *
 * The secret and the recovery codes are written through encrypted casts on the
 * model, so what this class holds in PHP is plaintext and what the database
 * holds is a ciphertext envelope. Neither is ever serialised into a response
 * except at the moment it is created, and neither is ever written into the
 * audit chain — the trail records that the factor was switched on or off, not
 * the material that makes it work.
 *
 * Enrolment and confirmation are separate steps on purpose. A secret written
 * but not yet confirmed cannot finish a login, so an enrolment abandoned
 * half-way leaves an account exactly as open as it was before it started.
 */
final class TwoFactor
{
    /**
     * Marks a ticket as one of ours, so a value encrypted for some other
     * purpose can never be replayed here as a sign-in challenge.
     */
    private const CHALLENGE_PURPOSE = 'nexas.two-factor.challenge';

    public function __construct(
        private readonly Totp $totp,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Whether this account has to answer a challenge before it gets a token.
     */
    public function enabledFor(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /**
     * Starts an enrolment: a new secret is written and any earlier one is
     * discarded, unconfirmed.
     *
     * @return array{secret: string, provisioning_uri: string}
     */
    public function begin(User $user): array
    {
        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'provisioning_uri' => $this->totp->provisioningUri(
                $secret,
                (string) $user->email,
                (string) config('app.name'),
            ),
        ];
    }

    /**
     * Turns the factor on, and hands back the recovery codes once.
     *
     * @return list<string>
     */
    public function confirm(User $user, string $code, ?int $companyId = null): array
    {
        $secret = $user->two_factor_secret;

        if ($secret === null) {
            throw AccessException::twoFactorNotStarted();
        }

        if (! $this->totp->verify($secret, $code)) {
            throw AccessException::twoFactorCodeRefused();
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->audit->record(
            'user.two_factor_enabled',
            $user,
            ['two_factor_enabled' => false],
            ['two_factor_enabled' => true],
            $companyId,
        );

        return $codes;
    }

    /**
     * Turns the factor off and forgets everything that made it work.
     */
    public function disable(User $user, ?int $companyId = null): void
    {
        if (! $this->enabledFor($user)) {
            throw AccessStateException::twoFactorNotEnabled();
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit->record(
            'user.two_factor_disabled',
            $user,
            ['two_factor_enabled' => true],
            ['two_factor_enabled' => false],
            $companyId,
        );
    }

    /**
     * Replaces the recovery codes, invalidating every code handed out before.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, ?int $companyId = null): array
    {
        if (! $this->enabledFor($user)) {
            throw AccessStateException::twoFactorNotEnabled();
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        $this->audit->record(
            'user.two_factor_recovery_codes_regenerated',
            $user,
            null,
            ['recovery_codes' => count($codes)],
            $companyId,
        );

        return $codes;
    }

    /**
     * Whether the presented code is the one the authenticator app is showing.
     */
    public function verifyCode(User $user, string $code): bool
    {
        return $user->two_factor_secret !== null && $this->totp->verify($user->two_factor_secret, $code);
    }

    /**
     * Whether the presented value is one of the account's unused recovery
     * codes. A code that matches is spent as it is accepted, so it cannot be
     * used twice even if it is written down.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        $presented = Str::lower(trim($code));

        $remaining = [];

        foreach ($codes as $index => $stored) {
            if (Str::lower($stored) === $presented) {
                $remaining = $codes;
                unset($remaining[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * How long a challenge stays exchangeable, in minutes.
     */
    public function challengeTtlMinutes(): int
    {
        return max(1, (int) config('nexas.auth.two_factor.challenge_ttl_minutes', 5));
    }

    /**
     * The encrypted ticket a password-only sign-in hands back.
     *
     * It is not a token and grants nothing: it names the account and the
     * company that were proved by the password, and it is worthless without
     * the code that has not been asked for yet. Encrypting rather than signing
     * it means the contents are not readable either, so it leaks neither the
     * account nor a way to guess at it.
     */
    public function issueChallenge(User $user, int $companyId): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => self::CHALLENGE_PURPOSE,
            'user_id' => $user->getKey(),
            'company_id' => $companyId,
            'expires_at' => now()->addMinutes($this->challengeTtlMinutes())->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{user: User, company_id: int}
     */
    public function consumeChallenge(string $ticket): array
    {
        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(Crypt::decryptString($ticket), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw AccessException::twoFactorChallengeInvalid();
        }

        if (($payload['purpose'] ?? null) !== self::CHALLENGE_PURPOSE
            || (int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw AccessException::twoFactorChallengeInvalid();
        }

        $companyId = (int) ($payload['company_id'] ?? 0);

        $user = User::query()->find($payload['user_id'] ?? null);

        // Membership is re-checked rather than trusted: a ticket minted while
        // somebody was a member must not still work after they were taken off
        // the company's books.
        if ($user === null
            || $user->status !== 'active'
            || $companyId === 0
            || ! $user->belongsToCompany($companyId)) {
            throw AccessException::twoFactorChallengeInvalid();
        }

        return ['user' => $user, 'company_id' => $companyId];
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        $count = max(1, (int) config('nexas.auth.two_factor.recovery_code_count', 8));

        $codes = [];

        for ($index = 0; $index < $count; $index++) {
            $codes[] = implode('-', str_split(Str::lower(Str::random(16)), 4));
        }

        return $codes;
    }
}
