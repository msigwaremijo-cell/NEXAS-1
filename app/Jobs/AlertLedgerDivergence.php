<?php

namespace App\Jobs;

use App\Models\OutboxEvent;
use App\Support\Enums\OutboxStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Raises an alert when the ledger_balances snapshot no longer agrees with the
 * ledger it caches.
 *
 * The check itself is read-only and elsewhere — LedgerVerification — and this
 * only records that it found something, so a scheduled run that nobody is
 * watching still leaves a durable trace. The alert is an `outbox_events` row,
 * written rather than merely logged, so it survives the process that raised it
 * and can be delivered by whatever consumes the outbox.
 *
 * An earlier alert that is still outstanding is left to stand, so a nightly
 * check does not pile up a fresh row for every tick while the books stay wrong.
 * Once that alert is processed, a later run may raise another if the snapshot is
 * still out of step.
 */
class AlertLedgerDivergence implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $divergences
     */
    public function __construct(
        public readonly int $companyId,
        public readonly array $divergences,
    ) {}

    public function handle(): void
    {
        // Nothing wrong is not an alert.
        if ($this->divergences === []) {
            return;
        }

        $alreadyOwed = OutboxEvent::query()
            ->where('company_id', $this->companyId)
            ->where('event_type', 'ledger.verification_failed')
            ->where('aggregate_type', 'company')
            ->where('aggregate_id', $this->companyId)
            ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Failed->value])
            ->exists();

        if ($alreadyOwed) {
            return;
        }

        OutboxEvent::create([
            'company_id' => $this->companyId,
            'event_type' => 'ledger.verification_failed',
            'aggregate_type' => 'company',
            'aggregate_id' => $this->companyId,
            'payload' => [
                'company_id' => $this->companyId,
                'divergence_count' => count($this->divergences),
                'divergences' => $this->divergences,
            ],
            'status' => OutboxStatus::Pending->value,
            'available_at' => now(),
        ]);
    }
}
