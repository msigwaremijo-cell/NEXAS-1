<?php

namespace App\Domain\Reporting\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Models\BudgetVersion;

/**
 * A budget asked to move in a way its state does not allow.
 *
 * A plan has a life, and each step of it is a deliberate act: a draft may be
 * edited and raised, a raised plan may be decided, an approved plan may be
 * locked and a locked plan may be unlocked. Asking for a step out of turn — or
 * editing a plan that has been signed off — is not a malformed request, so
 * resending it unchanged can never succeed. That is why this is reported as a
 * conflict rather than a validation error: the caller has named a real plan and
 * a real act, and the plan is simply not in a state where that act is legal.
 *
 * Every factory names the version and the state it was found in, because the
 * sentence the client shows a member should say which plan and why, not merely
 * that something was refused.
 */
final class BudgetStateException extends AccountingException
{
    public static function notEditable(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s is %s, so it can no longer be edited. '
            .'A change to a plan that has been raised is the next version, not an edit of this one.',
            $version->version_no,
            self::year($version),
            strtolower($version->status->label())
        ));
    }

    public static function alreadySubmitted(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s is %s and is already waiting on a decision.',
            $version->version_no,
            self::year($version),
            strtolower($version->status->label())
        ));
    }

    public static function notSubmittable(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s is %s, so it cannot be raised for approval. '
            .'Only a draft plan is put forward.',
            $version->version_no,
            self::year($version),
            strtolower($version->status->label())
        ));
    }

    public static function notPending(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s is %s, so there is nothing waiting to be decided.',
            $version->version_no,
            self::year($version),
            strtolower($version->status->label())
        ));
    }

    public static function notLockable(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s is %s, so it cannot be locked. '
            .'A plan is locked only once it has been approved.',
            $version->version_no,
            self::year($version),
            strtolower($version->status->label())
        ));
    }

    public static function notLocked(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s is %s, so there is nothing to unlock.',
            $version->version_no,
            self::year($version),
            strtolower($version->status->label())
        ));
    }

    public static function selfApprovalRefused(BudgetVersion $version): self
    {
        return new self(sprintf(
            'Version %d of the plan for %s was written or raised by the member now deciding it, '
            .'and nobody may approve their own plan. A second member — the Owner or another '
            .'Financial Manager — has to make this decision.',
            $version->version_no,
            self::year($version)
        ));
    }

    public static function reasonRequired(BudgetVersion $version): self
    {
        return new self(sprintf(
            'A change to the plan for %s has to say why it is being made. '
            .'Version %d would have replaced version %d, so a reason is required.',
            self::year($version),
            (int) $version->version_no + 1,
            (int) $version->version_no
        ));
    }

    public static function locked(BudgetVersion $version): self
    {
        return new self(sprintf(
            'The plan for %s is locked at version %d, so no further version may be started from it. '
            .'Unlock it first if the plan really has to change.',
            self::year($version),
            $version->version_no
        ));
    }

    private static function year(BudgetVersion $version): string
    {
        return (string) ($version->fiscalYear?->name ?? 'this fiscal year');
    }
}
