<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Exceptions\AccessException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switches a member's account on or off.
 *
 * Deactivation is an account-level act rather than a membership-level one, and
 * that is deliberate: a token is issued per person, not per company, so a
 * member who is switched off here would still hold a live credential for every
 * other company they belong to. Revoking access therefore means revoking the
 * account and every token minted against it, which is what makes "deactivated"
 * mean the same thing everywhere.
 *
 * The member making the request is the one case the action refuses. Everybody
 * else's access is the Owner's to withdraw, but their own is not: an
 * administrator who can sign themselves out of their own books is an
 * administrator who can leave the company unmanageable by accident.
 *
 * Reactivation is the same action with the other value, and it deliberately
 * does not restore tokens. Signing in again is the point at which new
 * credentials are issued.
 */
final class SetMemberStatus
{
    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(User $member, int $companyId, string $status, ?int $actorId = null): User
    {
        return DB::transaction(function () use ($member, $companyId, $status, $actorId): User {
            $locked = User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $from = $locked->status;

            // Asking for the state that already holds is a no-op rather than a
            // second audit entry: nothing changed, so nothing is recorded.
            if ($from === $status) {
                return $locked;
            }

            if ($status === self::INACTIVE && $actorId !== null && (int) $locked->getKey() === $actorId) {
                throw AccessException::selfDeactivationRefused($locked);
            }

            $locked->forceFill(['status' => $status])->save();

            if ($status === self::INACTIVE) {
                // A token that still authenticates is access, so switching the
                // account off signs every device out at once. This is what
                // makes a lost or departed member's session end here and not at
                // the next login.
                $locked->tokens()->delete();
            }

            $this->audit->record(
                $status === self::ACTIVE ? 'user.activated' : 'user.deactivated',
                $locked,
                ['status' => $from],
                ['status' => $status],
                $companyId,
            );

            return $locked;
        });
    }
}
