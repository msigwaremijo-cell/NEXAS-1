<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the ledger.
 *
 * journal_lines is the source of truth, and every query below is written so
 * MySQL can answer it from journal_lines_account_covering
 * (company_id, account_id, posting_date, id): company and account are
 * equalities that lead the index, the dates are a range on the next column,
 * and the line order the reports ask for is exactly the order the index is
 * already in, so no sort is needed on top of the scan.
 *
 * Only journals that have reached the ledger count: posted ones and reversed
 * ones, whose lines stay in the ledger and are cancelled by the mirror-image
 * journal that reversed them. A draft is the case worth spelling out — its
 * lines are written the moment the draft is created, so the draft rows are
 * physically present in the table long before they belong in a report. They are
 * excluded with an EXISTS subquery rather than a join on purpose: a join lets
 * the optimiser choose `journals` as the driving table and lose the index order,
 * while EXISTS keeps journal_lines primary and turns the status test into a
 * primary key lookup per candidate row.
 *
 * Amounts are read from debit/credit, the journal's own currency, which is the
 * same pair the posting trigger sums into journals.total_debit/total_credit.
 * The base_debit/base_credit columns hold the company-currency translation and
 * are not used here; translating a report into the reporting currency is a
 * separate concern that only matters once foreign-currency journals exist.
 */
final class LedgerQuery
{
    /**
     * The chart of accounts, in code order, paginated.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Account>
     */
    public function chartOfAccounts(int $companyId, array $filters, int $perPage): LengthAwarePaginator
    {
        return Account::query()
            ->where('company_id', $companyId)
            ->when(
                isset($filters['type']),
                fn (Builder $query): Builder => $query->where('account_type', $filters['type'])
            )
            ->when(
                isset($filters['postable']),
                fn (Builder $query): Builder => $query->where('is_postable', (bool) $filters['postable'])
            )
            ->when(
                isset($filters['search']),
                function (Builder $query) use ($filters): Builder {
                    $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']).'%';

                    return $query->where(
                        fn (Builder $inner): Builder => $inner
                            ->where('code', 'like', $term)
                            ->orWhere('name', 'like', $term)
                    );
                }
            )
            ->orderBy('code')
            ->paginate($perPage);
    }

    /**
     * One account's posted lines in journal order, newest page last.
     *
     * Exposed rather than private so the test suite can hand the exact SQL to
     * EXPLAIN and prove which index MySQL actually chooses for it.
     */
    public function ledgerLineQuery(int $companyId, int $accountId, ?string $from, ?string $to): Builder
    {
        return $this->postedLines($companyId, $accountId, $from, $to)
            ->orderBy('journal_lines.posting_date')
            ->orderBy('journal_lines.id');
    }

    /**
     * Per-account debit and credit totals for a date range.
     *
     * Exposed for the same reason as ledgerLineQuery: this is the scan the
     * trial balance is built from.
     */
    public function trialBalanceQuery(int $companyId, ?string $from, ?string $to): Builder
    {
        return $this->postedLines($companyId, null, $from, $to)
            ->selectRaw(
                'journal_lines.account_id AS account_id,'
                .' COALESCE(SUM(journal_lines.debit), 0) AS debit,'
                .' COALESCE(SUM(journal_lines.credit), 0) AS credit'
            )
            ->groupBy('journal_lines.account_id');
    }

    /**
     * General ledger for one account.
     *
     * A running balance cannot be computed from the page on screen alone: page
     * three opens with whatever the ledger recorded before its first line. That
     * opening figure is read back from MySQL through the same covering index the
     * page scan uses, so the number is the ledger's own, not a running total
     * that PHP guessed at.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function generalLedger(
        int $companyId,
        Account $account,
        ?string $from,
        ?string $to,
        int $page,
        int $perPage,
    ): array {
        $accountId = (int) $account->getKey();

        $lines = $this->ledgerLineQuery($companyId, $accountId, $from, $to)
            ->with('journal:id,journal_no,reference,status')
            ->forPage($page, $perPage)
            ->get();

        $total = $this->postedLines($companyId, $accountId, $from, $to)->count();

        [$rangeDebit, $rangeCredit] = $this->totals($this->postedLines($companyId, $accountId, $from, $to));

        // What the account already stood at before the range began.
        $opening = $from === null
            ? Money::zero()
            : $this->signedTotal(
                $this->postedLines($companyId, $accountId, null, null)
                    ->where('journal_lines.posting_date', '<', $from)
            );

        $first = $lines->first();

        // What the ledger held at the instant the first line of this page was
        // recorded, which is the opening figure on page one and grows by every
        // earlier page afterwards.
        $broughtForward = $first === null
            ? $opening
            : $this->signedTotal(
                $this->postedLines($companyId, $accountId, null, null)
                    ->where(function (Builder $query) use ($first): void {
                        $date = $first->posting_date->toDateString();

                        $query->where('journal_lines.posting_date', '<', $date)
                            ->orWhere(function (Builder $sameDay) use ($date, $first): void {
                                $sameDay->where('journal_lines.posting_date', $date)
                                    ->where('journal_lines.id', '<', (int) $first->getKey());
                            });
                    })
            );

        $closing = $opening->plus($rangeDebit)->minus($rangeCredit);

        $running = $broughtForward;
        $rows = [];

        foreach ($lines as $line) {
            $running = $running
                ->plus(Money::of((string) $line->debit))
                ->minus(Money::of((string) $line->credit));

            $rows[] = [
                'id' => (int) $line->getKey(),
                'journal_id' => (int) $line->journal_id,
                'journal_no' => $line->journal?->journal_no,
                'reference' => $line->journal?->reference,
                'line_no' => (int) $line->line_no,
                'posting_date' => $line->posting_date->toDateString(),
                'description' => $line->description,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
                'running_balance' => $running->toDecimal(),
            ];
        }

        return [
            'data' => $rows,
            'meta' => [
                'account_id' => $accountId,
                'account_ulid' => $account->ulid,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                'currency_code' => $account->currency_code,
                'from' => $from,
                'to' => $to,
                'opening_balance' => $opening->toDecimal(),
                'brought_forward' => $broughtForward->toDecimal(),
                'period_debit' => $rangeDebit->toDecimal(),
                'period_credit' => $rangeCredit->toDecimal(),
                'closing_balance' => $closing->toDecimal(),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $this->lastPage($total, $perPage),
            ],
        ];
    }

    /**
     * Trial balance for a date range: one row per account that moved, with the
     * movement split into its debit and credit sides.
     *
     * The account labels are fetched separately rather than joined in, so the
     * aggregate scan stays free of a second table and keeps the covering index.
     * The result set is bounded by the size of the chart of accounts, which is
     * why the page slice is taken here rather than in SQL.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function trialBalance(int $companyId, ?string $from, ?string $to, int $page, int $perPage): array
    {
        $totals = $this->trialBalanceQuery($companyId, $from, $to)->get();

        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $totals->pluck('account_id')->all())
            ->get()
            ->keyBy(fn (Account $account): int => (int) $account->getKey());

        $rows = [];

        foreach ($totals as $total) {
            $account = $accounts->get((int) $total->account_id);

            if ($account === null) {
                continue;
            }

            $debit = Money::of((string) $total->debit);
            $credit = Money::of((string) $total->credit);

            // An account whose movements cancel out in the range contributes
            // nothing to either side of the trial balance.
            if ($debit->isZero() && $credit->isZero()) {
                continue;
            }

            $rows[] = [
                'account_id' => (int) $account->getKey(),
                'account_ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                'debit' => $debit->toDecimal(),
                'credit' => $credit->toDecimal(),
                'net' => $debit->minus($credit)->toDecimal(),
            ];
        }

        usort($rows, fn (array $left, array $right): int => strcmp((string) $left['code'], (string) $right['code']));

        $totalDebit = Money::sum(array_map(fn (array $row): Money => Money::of($row['debit']), $rows));
        $totalCredit = Money::sum(array_map(fn (array $row): Money => Money::of($row['credit']), $rows));
        $count = count($rows);

        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'from' => $from,
                'to' => $to,
                'total_debit' => $totalDebit->toDecimal(),
                'total_credit' => $totalCredit->toDecimal(),
                'balanced' => $totalDebit->equals($totalCredit),
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $count,
                'last_page' => $this->lastPage($count, $perPage),
            ],
        ];
    }

    /**
     * Posted lines for one account, or for every account when none is named.
     *
     * No ordering: aggregate and count queries are built from this too, and an
     * ORDER BY on a column that is not in an aggregate's select list is
     * rejected outright by ONLY_FULL_GROUP_BY.
     */
    private function postedLines(int $companyId, ?int $accountId, ?string $from, ?string $to): Builder
    {
        return JournalLine::query()
            ->where('journal_lines.company_id', $companyId)
            ->when($accountId !== null, fn (Builder $query): Builder => $query->where('journal_lines.account_id', $accountId))
            ->when($from !== null, fn (Builder $query): Builder => $query->where('journal_lines.posting_date', '>=', $from))
            ->when($to !== null, fn (Builder $query): Builder => $query->where('journal_lines.posting_date', '<=', $to))
            ->whereExists(function (QueryBuilder $query): void {
                $query->select(DB::raw('1'))
                    ->from('journals')
                    ->whereColumn('journals.id', 'journal_lines.journal_id')
                    ->whereIn('journals.status', JournalStatus::ledgerStatuses());
            });
    }

    /**
     * @return array{0: Money, 1: Money} gross debit and gross credit
     */
    private function totals(Builder $query): array
    {
        $row = $query->toBase()
            ->select(DB::raw(
                'COALESCE(SUM(journal_lines.debit), 0) AS total_debit,'
                .' COALESCE(SUM(journal_lines.credit), 0) AS total_credit'
            ))
            ->first();

        return [Money::of((string) $row->total_debit), Money::of((string) $row->total_credit)];
    }

    private function signedTotal(Builder $query): Money
    {
        [$debit, $credit] = $this->totals($query);

        return $debit->minus($credit);
    }

    private function lastPage(int $total, int $perPage): int
    {
        return max(1, (int) ceil($total / max($perPage, 1)));
    }
}
