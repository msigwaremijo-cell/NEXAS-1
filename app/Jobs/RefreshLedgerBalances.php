<?php

namespace App\Jobs;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Models\FiscalPeriod;
use App\Models\LedgerBalance;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
 * Every bucket is a gross debit or credit total, never a signed net, because
 * chk_ledger_balances_non_negative rejects a negative value in any of the six
 * money columns. A credit-side closing balance is therefore stored as
 * closing_credit > 0 with closing_debit = 0, and the other way round for a debit
 * balance.
 *
 * Only journals that reached the ledger are counted, drafts are not. A draft's
 * lines already exist in journal_lines, so the status test has to be explicit or
 * drafts would be capitalised into the snapshot. Reversed journals are counted
 * alongside posted ones, because their lines are still part of the ledger and
 * are offset by the reversal that cancelled them.
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

        $startsOn = $period->starts_on->toDateString();
        $endsOn = $period->ends_on->toDateString();

        // Everything the account stood at before the period opened, as gross
        // debit and gross credit rather than a net.
        $opening = $this->baseQuery($endsOn)
            ->where('journal_lines.posting_date', '<', $startsOn)
            ->selectRaw(
                'journal_lines.account_id AS account_id,'
                .' COALESCE(SUM(journal_lines.debit), 0) AS debit,'
                .' COALESCE(SUM(journal_lines.credit), 0) AS credit'
            )
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->account_id);

        // What moved inside the period, plus the last line id so a later
        // incremental refresh knows where this snapshot stopped.
        $movement = $this->baseQuery($endsOn)
            ->whereBetween('journal_lines.posting_date', [$startsOn, $endsOn])
            ->selectRaw(
                'journal_lines.account_id AS account_id,'
                .' COALESCE(SUM(journal_lines.debit), 0) AS debit,'
                .' COALESCE(SUM(journal_lines.credit), 0) AS credit,'
                .' MAX(journal_lines.id) AS last_line_id'
            )
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->account_id);

        $accountIds = $opening->keys()
            ->merge($movement->keys())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        DB::transaction(function () use ($opening, $movement, $accountIds): void {
            foreach ($accountIds as $accountId) {
                $opened = $opening->get($accountId);
                $moved = $movement->get($accountId);

                $openingNet = Money::of((string) ($opened?->debit ?? '0'))
                    ->minus(Money::of((string) ($opened?->credit ?? '0')));

                $periodDebit = Money::of((string) ($moved?->debit ?? '0'));
                $periodCredit = Money::of((string) ($moved?->credit ?? '0'));

                $closingNet = $openingNet->plus($periodDebit)->minus($periodCredit);

                LedgerBalance::query()->updateOrCreate(
                    [
                        'company_id' => $this->companyId,
                        'account_id' => $accountId,
                        'fiscal_period_id' => $this->fiscalPeriodId,
                    ],
                    [
                        'opening_debit' => $this->debitBucket($openingNet),
                        'opening_credit' => $this->creditBucket($openingNet),
                        'period_debit' => $periodDebit->toDecimal(),
                        'period_credit' => $periodCredit->toDecimal(),
                        'closing_debit' => $this->debitBucket($closingNet),
                        'closing_credit' => $this->creditBucket($closingNet),
                        'last_journal_line_id' => $moved?->last_line_id,
                        'refreshed_at' => now(),
                    ]
                );
            }

            // An account whose lines have all been reversed out of the period
            // must not keep a stale snapshot.
            LedgerBalance::query()
                ->where('company_id', $this->companyId)
                ->where('fiscal_period_id', $this->fiscalPeriodId)
                ->whereNotIn('account_id', $accountIds->all())
                ->delete();
        });
    }

    /**
     * Posted lines up to the end of the period, for this company.
     */
    private function baseQuery(string $upto): QueryBuilder
    {
        return DB::table('journal_lines')
            ->where('journal_lines.company_id', $this->companyId)
            ->where('journal_lines.posting_date', '<=', $upto)
            ->whereExists(function (QueryBuilder $query): void {
                $query->select(DB::raw('1'))
                    ->from('journals')
                    ->whereColumn('journals.id', 'journal_lines.journal_id')
                    ->whereIn('journals.status', JournalStatus::ledgerStatuses());
            });
    }

    private function debitBucket(Money $net): string
    {
        return $net->isNegative() ? Money::zero()->toDecimal() : $net->toDecimal();
    }

    private function creditBucket(Money $net): string
    {
        return $net->isNegative() ? $net->absolute()->toDecimal() : Money::zero()->toDecimal();
    }
}
