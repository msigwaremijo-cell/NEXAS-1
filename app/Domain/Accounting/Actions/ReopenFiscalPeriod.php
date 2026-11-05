<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\FiscalStatus;
use App\Domain\Accounting\Exceptions\FiscalPeriodStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\DB;

/**
 * Reopens one closed fiscal period so a correction can be posted into it.
 *
 * This is the act the year-end close is reversible by, and it is deliberately
 * narrow. Only the period named is reopened; every other period of the year
 * stays closed, so reopening December does not quietly put the rest of the year
 * back into play. The year above it is reopened with it, because a posting
 * needs both to be open, and nothing else about the year is touched — no
 * balances are moved and no journal is written.
 *
 * A reason is not optional and is not decoration. A reopen puts a period that
 * has already been reported back into the books, so the audit chain records
 * who reopened it, when, and why. The reason has no column of its own; it is
 * carried in the same entry with the state change, so the two can never drift
 * apart.
 *
 * Closing the period and the year again afterwards is the ordinary year-end
 * close: it re-derives the closing entry from the accounts' balance, so the
 * correcting journal's effect is carried into retained earnings rather than
 * left sitting on the temporary accounts.
 */
final class ReopenFiscalPeriod
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(FiscalPeriod $period, string $reason, ?int $reopenedByUserId = null): array
    {
        return DB::transaction(function () use ($period, $reason): array {
            // The year is locked before the period, and the year-end close takes
            // the same two rows in the same order, so a close and a reopen
            // cannot deadlock against each other.
            $year = FiscalYear::query()
                ->whereKey($period->fiscal_year_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked = FiscalPeriod::query()
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->canBeReopened()) {
                throw FiscalPeriodStateException::notReopenable($locked);
            }

            if ($year->status === FiscalStatus::Locked) {
                throw FiscalPeriodStateException::yearLocked($locked);
            }

            $yearReopened = ! $year->status->acceptsPostings();

            $locked->forceFill([
                'status' => FiscalStatus::Open,
                'closed_at' => null,
                'closed_by_user_id' => null,
                'row_version' => (int) $locked->row_version + 1,
            ])->save();

            if ($yearReopened) {
                $year->forceFill([
                    'status' => FiscalStatus::Open,
                    'row_version' => (int) $year->row_version + 1,
                ])->save();
            }

            $this->audit->record('fiscal_period.reopened', $locked, [
                'status' => FiscalStatus::Closed->value,
            ], [
                'status' => $locked->status->value,
                'reason' => $reason,
                // Named on the year's own entry as well, because the reopen is
                // two state changes and the trail has to be readable from
                // either side of it.
                'fiscal_year_status' => $year->status->value,
                'fiscal_year_reopened' => $yearReopened,
            ]);

            if ($yearReopened) {
                $this->audit->record('fiscal_year.reopened', $year, [
                    'status' => FiscalStatus::Closed->value,
                ], [
                    'status' => $year->status->value,
                    'reason' => $reason,
                    'reopened_for_period' => $locked->name,
                ]);
            }

            return [
                'fiscal_period_id' => (int) $locked->getKey(),
                'fiscal_period_ulid' => $locked->ulid,
                'name' => $locked->name,
                'period_no' => (int) $locked->period_no,
                'starts_on' => $locked->starts_on->toDateString(),
                'ends_on' => $locked->ends_on->toDateString(),
                'status' => $locked->status->value,
                'reason' => $reason,
                'fiscal_year' => [
                    'id' => (int) $year->getKey(),
                    'ulid' => $year->ulid,
                    'name' => $year->name,
                    'status' => $year->status->value,
                ],
            ];
        });
    }
}
