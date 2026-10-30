<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\User;

/**
 * Who may read the audit chain.
 *
 * `audit.view` is its own right rather than part of `report.view`, and only the
 * Owner and the Auditor hold it. A report says what the books now hold; the
 * audit log says who changed them and what they held before, which is the
 * evidence behind every other number — so a member who is trusted to read the
 * books is not automatically trusted to read the trail. The Read-only role is
 * exactly that member, and is refused here.
 *
 * There is no write ability at all. The table is append-only and a trigger
 * rejects UPDATE and DELETE outright, so there is nothing to authorise.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_AUDIT_VIEW);
    }
}
