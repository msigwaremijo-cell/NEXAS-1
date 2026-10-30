<?php

namespace App\Jobs;

use App\Domain\Accounting\Services\LedgerBalanceCalculator;
use App\Models\FiscalPeriod;
use App\Models\LedgerBalance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the ledger_balances snapshot for one company and one fiscal period.
 *
 * journal_lines stays the source of truth. This table is a cache, and the job is
 * written so that a full rebuild always produces the same figures as reading the
 * ledger directly — that is what makes it safe to run again at any time, and
 * what the test suite checks the snapshot against.
 *
 * The arithmetic itself lives in LedgerBalanceCalculator, shared with the check
 * that proves the snapshot still agrees with the ledger. The job only decides
 * which rows to write and which stale ones to remove.
 */
class RefreshLedgerBalances implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $companyId,
        public readonly int $fiscalPeriodId,
    ) {}

    public function handle(): void
    {
        $period = FiscalPeriod::query()
            ->where('company_id', $this->companyId)
            ->whereKey($this->fiscalPeriodId)
            ->firstOrFail();

        $buckets = app(LedgerBalanceCalculator::class)->forPeriod(
            $this->companyId,
            $period->starts_on->toDateString(),
            $period->ends_on->toDateString(),
        );

        DB::transaction(function () use ($buckets): void {
            foreach ($buckets as $accountId => $bucket) {
                LedgerBalance::query()->updateOrCreate(
                    [
                        'company_id' => $this->companyId,
                        'account_id' => (int) $accountId,
                        'fiscal_period_id' => $this->fiscalPeriodId,
                    ],
                    $bucket + ['refreshed_at' => now()]
                );
            }

            // An account whose lines have all been reversed out of the period
            // must not keep a stale snapshot.
            LedgerBalance::query()
                ->where('company_id', $this->companyId)
                ->where('fiscal_period_id', $this->fiscalPeriodId)
                ->whereNotIn('account_id', array_keys($buckets))
                ->delete();
        });
    }
}
