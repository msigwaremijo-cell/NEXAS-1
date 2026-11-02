<?php

namespace App\Domain\Approvals\Enums;

/**
 * Mirrors the `approval_requests.status` column comment
 * ('pending|approved|rejected').
 *
 * A decision is final: a rejected document is not reopened by approving it
 * later, it is raised again as a new request, so the queue keeps the whole
 * story rather than only the latest word on it.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isDecided(): bool
    {
        return $this !== self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
