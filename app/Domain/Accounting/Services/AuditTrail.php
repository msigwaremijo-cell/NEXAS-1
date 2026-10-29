<?php

namespace App\Domain\Accounting\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one link into the append-only audit chain.
 *
 * previous_hash and hash are left out on purpose: the BEFORE INSERT trigger
 * locks audit_chain_head, copies the chain head into previous_hash and
 * computes hash with SHA2, so no PHP code path can write a row that does not
 * extend the chain correctly.
 *
 * The entry is written inside the caller's transaction, which means an audit
 * record and the change it describes commit or roll back together.
 */
final class AuditTrail
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  int|null  $companyId  Named explicitly when the subject is the
     *                               company itself, which carries no
     *                               `company_id` attribute to read.
     */
    public function record(
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        ?int $companyId = null,
    ): AuditLog {
        return AuditLog::create([
            'company_id' => $companyId ?? $subject->getAttribute('company_id'),
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'ip_address' => $this->ipAddress(),
            'user_agent' => $this->userAgent(),
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * There is no request to read in a console command, a queued job or a
     * test, so the request details are left empty rather than invented.
     */
    private function ipAddress(): ?string
    {
        return App::runningInConsole() ? null : request()->ip();
    }

    private function userAgent(): ?string
    {
        return App::runningInConsole() ? null : request()->userAgent();
    }
}
