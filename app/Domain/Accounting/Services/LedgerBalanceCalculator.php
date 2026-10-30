<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Support\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the opening, movement and closing buckets for one fiscal period
 * straight from journal_lines.
 *
 * journal_lines is the source of truth and ledger_balances is a cache of it.
 * Two callers need the same sum: the job that writes the cache, and the check
 * that proves the cache still agrees with the ledger. They share this method so
 * they cannot drift — if the two carried their own copies of the arithmetic, a
 * bug in one would look like a disagreement in the data rather than a bug.
 *
 * Every bucket is a gross debit or credit total, never a signed net, because
 * chk_ledger_balances_non_negative rejects a negative value in any of the six
 * money columns. A credit-side balance is therefore stored as credit > 0 with
 * debit = 0, and the other way round for a debit balance.
 *
 * Only journals that reached the ledger are counted, drafts are not: a draft's
 * lines already exist in journal_lines, so the status test has to be explicit
 * or drafts would be capitalised into the snapshot. Reversed journals are
 * counted alongside posted ones, because their lines are still part of the
 * ledger and are offset by the reversal that cancelled them.
 */
final class LedgerBalanceCalculator
{
    /**
     * @return array<int, array{
     *     opening_debit: string,
     *     opening_credit: string,
     *     period_debit: string,
     *     period_credit: string,
     *     closing_debit: string,
     *     closing_credit: string,
     *     last_journal_line_id: ?int
     * }>
     */
    public function forPeriod(int $companyId, string $startsOn, string $endsOn): array
    {
        // Everything each account stood at before the period opened, as gross
        // debit and gross credit rather than a net.
        $opening = $this->postedLines($companyId, $endsOn)
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
        $movement = $this->postedLines($companyId, $endsOn)
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

        $buckets = [];

        $accountIds = $opening->keys()
            ->merge($movement->keys())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($accountIds as $accountId) {
            $accountId = (int) $accountId;
            $opened = $opening->get($accountId);
            $moved = $movement->get($accountId);

            $openingNet = Money::of((string) ($opened?->debit ?? '0'))
                ->minus(Money::of((string) ($opened?->credit ?? '0')));

            $periodDebit = Money::of((string) ($moved?->debit ?? '0'));
            $periodCredit = Money::of((string) ($moved?->credit ?? '0'));

            $closingNet = $openingNet->plus($periodDebit)->minus($periodCredit);

            $buckets[$accountId] = [
                'opening_debit' => $this->debitBucket($openingNet),
                'opening_credit' => $this->creditBucket($openingNet),
                'period_debit' => $periodDebit->toDecimal(),
                'period_credit' => $periodCredit->toDecimal(),
                'closing_debit' => $this->debitBucket($closingNet),
                'closing_credit' => $this->creditBucket($closingNet),
                'last_journal_line_id' => $moved?->last_line_id === null ? null : (int) $moved->last_line_id,
            ];
        }

        return $buckets;
    }

    /**
     * Posted lines up to the end of the period, for this company.
     */
    private function postedLines(int $companyId, string $upto): QueryBuilder
    {
        return DB::table('journal_lines')
            ->where('journal_lines.company_id', $companyId)
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
