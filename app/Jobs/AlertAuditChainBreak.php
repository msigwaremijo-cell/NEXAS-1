<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Support\Enums\OutboxStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Raises an alert when the audit hash chain no longer verifies.
 *
 * The check itself is read-only and elsewhere — audit:verify — and this only
 * records that it found a break, so a scheduled run nobody watched still
 * leaves a durable trace. The alert is an `outbox_events` row, written rather
 * than merely logged, so it survives the process that raised it and can be
 * delivered by whatever consumes the outbox.
 *
 * The chain is global, not per company: one break anywhere makes every later
 * link untrustworthy, and the row that broke may belong to a company the run
 * cannot attribute. So the event is deduplicated across the whole table, and
 * an alert already outstanding is left to stand rather than a fresh row being
 * piled up for every tick while the chain stays broken.
 */
class AlertAuditChainBreak implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $break
     */
    public function __construct(
        public readonly ?int $companyId,
        public readonly array $break,
    ) {}

    public function handle(): void
    {
        // Nothing wrong is not an alert.
        if ($this->break === []) {
            return;
        }

        $alreadyOwed = OutboxEvent::query()
            ->where('event_type', 'audit.chain_broken')
            ->where('aggregate_type', 'audit_chain')
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
            ->exists();

        if ($alreadyOwed) {
            return;
        }

        OutboxEvent::create([
            'company_id' => $this->companyId,
            'event_type' => 'audit.chain_broken',
            'aggregate_type' => 'audit_chain',
            'aggregate_id' => $this->break['audit_log_id'] ?? null,
            'payload' => $this->break,
            'status' => OutboxStatus::Pending->value,
            'available_at' => now(),
        ]);
    }
}
