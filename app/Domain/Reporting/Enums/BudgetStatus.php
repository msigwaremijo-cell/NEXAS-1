<?php

namespace App\Domain\Reporting\Enums;

/**
 * Where a budget version stands in its life.
 *
 * A plan moves in one direction only, and every move is a deliberate act by a
 * named member rather than a side effect of a save:
 *
 *   Draft             the plan is editable. Lines may be written and replaced
 *                     freely, and no approval has been asked for.
 *
 *   PendingApproval   the plan has been raised. Its amounts are frozen — the
 *                     database refuses a change to the lines — and it waits in
 *                     the approvals queue for a second member.
 *
 *   Approved           a member other than the author agreed to the plan. It is
 *                     frozen exactly as it was when it was raised, and it is the
 *                     version the company means to follow. It may still be
 *                     changed, but only by writing the next version, which
 *                     leaves this one untouched.
 *
 *   Locked            the plan is closed. Nothing more may be raised against it
 *                     and no further version may be started from it until an
 *                     authorised member unlocks it. This is the state a period
 *                     that has been closed leaves its plan in.
 *
 * A rejected version does not get a state of its own: it returns to Draft, with
 * the reason recorded, so the author may correct it and raise it again. The
 * database trigger is expressed against Draft for that reason — anything that
 * is not a draft is frozen.
 */
enum BudgetStatus: string
{
    case Draft = 'draft';

    case PendingApproval = 'pending_approval';

    case Approved = 'approved';

    case Locked = 'locked';

    /**
     * Only a draft may be edited. Every other state is frozen, which is the
     * same rule the integrity trigger enforces on the lines.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the version may be raised for approval. A plan already waiting on
     * a decision, or one that has been signed off, is not raised twice.
     */
    public function isSubmittable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether the version may still be decided. Only a version that is actually
     * waiting on a decision may be approved or rejected.
     */
    public function isDecidable(): bool
    {
        return $this === self::PendingApproval;
    }

    /**
     * Whether the version may be locked. A lock follows a decision, so only an
     * approved plan is closed.
     */
    public function isLockable(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Whether the version counts as the company's standing plan: one that has
     * been agreed to, whether or not it has since been closed.
     */
    public function isAgreed(): bool
    {
        return $this === self::Approved || $this === self::Locked;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Locked => 'Locked',
        };
    }
}
