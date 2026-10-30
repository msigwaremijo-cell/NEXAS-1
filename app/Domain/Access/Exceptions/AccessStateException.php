<?php

namespace App\Domain\Access\Exceptions;

use App\Models\User;

/**
 * A member change that collides with the team as it currently stands.
 *
 * The request itself is well formed, so resending it unchanged will produce the
 * same answer every time: this is a 409 rather than the 422 its parent throws.
 */
final class AccessStateException extends AccessException
{
    /**
     * Removing this role would leave the company with no Owner.
     *
     * The Owner is the only role that may manage members and the only one that
     * may close a year, so the last one is not a member who happens to hold a
     * role — they are the role that keeps the company administrable. Losing
     * them is a state the books cannot be recovered from through the API, which
     * is why the removal is refused rather than carried out with a warning.
     */
    public static function lastOwnerRefused(User $member, string $role): self
    {
        return new self(sprintf(
            '%s is the last member holding the %s role in this company, and removing it would leave nobody able to manage it.',
            $member->name,
            $role
        ));
    }

    /**
     * The person being invited is already on the company's books.
     *
     * Membership is the thing being created, so an existing member is a
     * collision with the state of the team rather than bad input: the answer is
     * to change their role, not to add them a second time.
     */
    public static function alreadyMember(User $member): self
    {
        return new self(sprintf(
            '%s is already a member of this company.',
            $member->name
        ));
    }
}
