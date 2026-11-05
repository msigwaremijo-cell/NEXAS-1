<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\FiscalStatus;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\FiscalYearStateException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Reporting\Services\LedgerQuery;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Closes a fiscal year: clears revenue and expense into retained earnings, then
 * stops the year accepting postings.
 *
 * The two halves are one act on purpose. A closing entry on its own would be an
 * entry somebody could post again by hand, and a lock on its own would leave the
 * temporary accounts carrying a result that has been reported but never moved.
 * Together they are the difference between a year that has been reported and a
 * year that has been finished.
 *
 * The amounts come from the accounts' whole balance as at the year's last day,
 * not from the year's own movement. The two agree whenever every earlier year
 * was closed, which is the normal case, and where one was not the whole balance
 * is the answer that gets the residue off the temporary accounts rather than
 * leaving it there for ever.
 *
 * Only accounts still in the chart are closed, because CreateJournal refuses to
 * post to a soft-deleted one. An account taken out of the chart with a balance
 * still on it therefore keeps that balance, and the balance sheet keeps showing
 * it in the unclosed result — which is worse to look at than to have happen, and
 * better than a close that half-succeeds.
 *
 * The retained earnings account is found by code rather than by a setting. The
 * seeded chart is the company's chart, and 3200 is the account every report and
 * every accountant already reads as retained earnings.
 */
final class CloseFiscalYear
{
    /**
     * The seeded chart's Retained Earnings account.
     */
    private const RETAINED_EARNINGS_CODE = '3200';

    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly AuditTrail $audit,
        private readonly LedgerQuery $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(FiscalYear $year, ?int $closedByUserId = null): array
    {
        return DB::transaction(function () use ($year, $closedByUserId): array {
            $locked = FiscalYear::query()
                ->whereKey($year->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->acceptsPostings()) {
                throw FiscalYearStateException::notOpen($locked);
            }

            $companyId = (int) $locked->company_id;
            $endsOn = $locked->ends_on->toDateString();

            $lines = $this->closingLines($companyId, $endsOn);

            $journal = null;

            // The journal is written and posted while the year is still open:
            // it is dated the year's last day, and posting it is exactly what
            // the year has to accept before it is closed.
            if ($lines !== []) {
                $this->openClosingPeriod($locked, $endsOn);

                $journal = ($this->create)(new JournalEntryData(
                    companyId: $companyId,
                    postingDate: $endsOn,
                    journalType: JournalType::Closing,
                    lines: $lines,
                    reference: 'Year-end close '.$locked->name,
                    memo: 'Closing entries for '.$locked->name.'.',
                    createdByUserId: $closedByUserId,
                ));

                $journal = ($this->post)($journal, null, $closedByUserId);
            }

            $periods = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->where('fiscal_year_id', $locked->getKey())
                ->get();

            $closedAt = now();

            foreach ($periods as $period) {
                $period->forceFill([
                    'status' => FiscalStatus::Closed,
                    'closed_at' => $closedAt,
                    'closed_by_user_id' => $closedByUserId,
                    'row_version' => (int) $period->row_version + 1,
                ])->save();
            }

            $locked->forceFill([
                'status' => FiscalStatus::Closed,
                'row_version' => (int) $locked->row_version + 1,
            ])->save();

            $this->audit->record('fiscal_year.closed', $locked, [
                'status' => FiscalStatus::Open->value,
            ], [
                'status' => $locked->status->value,
                'periods_closed' => $periods->count(),
                'closing_journal_no' => $journal?->journal_no,
            ]);

            return [
                'fiscal_year_id' => (int) $locked->getKey(),
                'fiscal_year_ulid' => $locked->ulid,
                'name' => $locked->name,
                'starts_on' => $locked->starts_on->toDateString(),
                'ends_on' => $locked->ends_on->toDateString(),
                'status' => $locked->status->value,
                'periods_closed' => $periods->count(),
                'closing_journal' => $journal === null ? null : [
                    'id' => (int) $journal->getKey(),
                    'ulid' => $journal->ulid,
                    'journal_no' => $journal->journal_no,
                    'journal_type' => $journal->journal_type->value,
                    'status' => $journal->status->value,
                    'posting_date' => $journal->posting_date->toDateString(),
                    'total_debit' => $journal->total_debit,
                    'total_credit' => $journal->total_credit,
                ],
            ];
        });
    }

    /**
     * The closing entry is dated the year's last day, so it lands in the year's
     * final period, and a period has to be open to be posted into.
     *
     * On a first close that period is already open and this does nothing. On a
     * second — the one that follows a reopen — the period that was reopened may
     * be an earlier one, and the final period is still shut from the close
     * before it. Writing the closing entry still has to be possible there, so
     * the final period is opened here for the length of this transaction and
     * shut again by the loop that follows, in the same transaction: nothing
     * outside ever sees it open. A period that has been locked for good is left
     * alone, and posting the closing entry then fails with the sentence that
     * says so rather than being forced through.
     *
     * The year is locked before the period here, and the reopen action takes
     * the same two rows in the same order, so a close and a reopen cannot
     * deadlock against each other.
     */
    private function openClosingPeriod(FiscalYear $year, string $endsOn): void
    {
        $period = FiscalPeriod::query()
            ->where('company_id', $year->company_id)
            ->where('fiscal_year_id', $year->getKey())
            ->where('starts_on', '<=', $endsOn)
            ->where('ends_on', '>=', $endsOn)
            ->orderBy('period_no')
            ->lockForUpdate()
            ->first();

        if ($period === null || $period->status !== FiscalStatus::Closed) {
            return;
        }

        $period->forceFill([
            'status' => FiscalStatus::Open,
            'closed_at' => null,
            'closed_by_user_id' => null,
            'row_version' => (int) $period->row_version + 1,
        ])->save();
    }

    /**
     * One line per temporary account that still carries a balance, plus the line
     * that clears the net into retained earnings.
     *
     * A closing entry works by posting the opposite of what an account holds, so
     * the ledger figure — debit minus credit — is negated rather than reversed
     * by hand: a positive balance is credited away, a negative one debited away.
     * The two sides are then compared and the difference, which is the year's
     * result, is taken to retained earnings as a credit when it is a profit and
     * a debit when it is a loss.
     *
     * @return list<JournalLineData>
     */
    private function closingLines(int $companyId, string $endsOn): array
    {
        $accounts = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('account_type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = $this->ledger->accountBalances(
            $companyId,
            $accounts->map(static fn (Account $account): int => (int) $account->getKey())->all(),
            $endsOn,
        );

        $lines = [];

        foreach ($accounts as $account) {
            $balance = $balances[(int) $account->getKey()] ?? Money::zero();

            if ($balance->isZero()) {
                continue;
            }

            $description = 'Close '.$account->code.' '.$account->name;

            $lines[] = $balance->isPositive()
                ? JournalLineData::credit((int) $account->getKey(), $balance, $description)
                : JournalLineData::debit((int) $account->getKey(), $balance->absolute(), $description);
        }

        if ($lines === []) {
            return [];
        }

        $retained = $this->retainedEarnings($companyId);

        $debited = Money::sum(array_map(static fn (JournalLineData $line): Money => $line->debit, $lines));
        $credited = Money::sum(array_map(static fn (JournalLineData $line): Money => $line->credit, $lines));
        $result = $debited->minus($credited);

        // Debited revenue against credited expense: what is left over is the
        // result. A profit leaves the closing entry short of credits and is
        // credited to retained earnings; a loss leaves it short of debits.
        if ($result->isPositive()) {
            $lines[] = JournalLineData::credit(
                (int) $retained->getKey(),
                $result,
                'Result for the year'
            );
        } elseif ($result->isNegative()) {
            $lines[] = JournalLineData::debit(
                (int) $retained->getKey(),
                $result->absolute(),
                'Result for the year'
            );
        }

        return $lines;
    }

    private function retainedEarnings(int $companyId): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->where('code', self::RETAINED_EARNINGS_CODE)
            ->orderBy('id')
            ->first();

        if ($account === null || ! $account->is_postable) {
            throw FiscalYearStateException::retainedEarningsMissing(
                $companyId,
                self::RETAINED_EARNINGS_CODE
            );
        }

        return $account;
    }
}
