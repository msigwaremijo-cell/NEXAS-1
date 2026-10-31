<?php

namespace App\Domain\Access\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Models\User;

/**
 * A member change the caller can fix and resubmit.
 *
 * These are refusals about the shape of the request itself — deactivating your
 * own account, removing a role the member does not hold — so they map to a 422
 * rather than the 409 that AccessStateException uses for a change that collides
 * with the team as it currently stands.
 */
class AccessException extends AccountingException
{
    /**
     * A member may not deactivate themselves.
     *
     * The point of an administration surface is that somebody else can take
     * your access away; a member who can switch their own account off has a
     * way to leave the company with nobody able to manage it, and a way to
     * lock themselves out of their own books.
     */
    public static function selfDeactivationRefused(User $member): self
    {
        return new self(sprintf(
            '%s is the member making this request, and a member cannot deactivate their own account.',
            $member->name
        ));
    }

    /**
     * The role being removed is not one the member holds, so there is nothing
     * to take away. Refused rather than ignored, because a screen that silently
     * answers "done" to a removal that never happened is worse than one that
     * says the member never held the role.
     */
    public static function roleNotHeld(User $member, string $role): self
    {
        return new self(sprintf(
            '%s does not hold the %s role in this company, so there is nothing to remove.',
            $member->name,
            $role
        ));
    }

    /**
     * A confirmation was sent for an enrolment that was never started.
     *
     * The caller can fix this by beginning the enrolment again, so it is a 422
     * rather than a state collision.
     */
    public static function twoFactorNotStarted(): self
    {
        return new self('Two-factor enrolment has not been started for this account, so there is nothing to confirm.');
    }

    /**
     * The one-time code did not match, or its window had already passed.
     */
    public static function twoFactorCodeRefused(): self
    {
        return new self('That one-time code is not valid. Check the code in your authenticator app and try again.');
    }

    /**
     * The half-finished login could not be exchanged for a token.
     *
     * The ticket is a credential in flight: it is encrypted, short-lived, and
     * refused rather than explained when it is tampered with, replayed after
     * its window, or presented for an account that is no longer active.
     */
    public static function twoFactorChallengeInvalid(): self
    {
        return new self('This sign-in challenge is no longer valid. Sign in again to receive a new one.');
    }
}
