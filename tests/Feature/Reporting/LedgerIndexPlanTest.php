<?php

use App\Domain\Reporting\Services\LedgerQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccountingFixtures;

// The reports are only as fast as the index MySQL actually chooses, and the
// choice is the optimiser's to make, so it is asked directly rather than
// assumed. This runs against real MySQL for that reason.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    // Lines spread across a year, so the month the report asks for is a small
    // slice of the account rather than the whole of it. That is what makes the
    // plan a costing decision instead of a table small enough to scan for free.
    for ($month = 1; $month <= 12; $month++) {
        $date = sprintf('2026-%02d-15', $month);

        for ($i = 1; $i <= 30; $i++) {
            $journalId = $this->makeJournal(sprintf('JV-%02d-%03d', $month, $i));

            // The date is set while the journal is still a draft, because the
            // period a posting is judged against is resolved separately.
            DB::table('journals')->where('id', $journalId)->update(['posting_date' => $date]);

            $this->addLine($journalId, 1, $this->cashAccountId, '10.0000', '0', $date);
            $this->addLine($journalId, 2, $this->revenueAccountId, '0', '10.0000', $date);

            $this->postJournal($journalId);
        }
    }
});

/**
 * The plan MySQL chooses for a compiled Eloquent query.
 *
 * @return array<string, mixed>
 */
function nxPlan(Builder $query): array
{
    return (array) DB::select('EXPLAIN '.$query->toSql(), $query->getBindings())[0];
}

it('answers the general ledger from the account covering index', function () {
    $query = app(LedgerQuery::class)
        ->ledgerLineQuery($this->companyId, $this->cashAccountId, '2026-03-01', '2026-03-31');

    $plan = nxPlan($query);

    // journal_lines_account_covering is (company_id, account_id, posting_date,
    // id): the two equalities lead, the dates are a range on the next column,
    // and the index is already in the order the report asks for.
    expect($plan['table'])->toBe('journal_lines')
        ->and($plan['key'])->toBe('journal_lines_account_covering')
        // Because the orders match, the scan is not followed by a sort.
        ->and((string) $plan['Extra'])->not->toContain('Using filesort');
});

it('answers the trial balance from the account covering index', function () {
    $query = app(LedgerQuery::class)
        ->trialBalanceQuery($this->companyId, '2026-03-01', '2026-03-31');

    $plan = nxPlan($query);

    // The same index groups the movement by account: account_id follows
    // company_id in it, so the grouping arrives in index order.
    expect($plan['table'])->toBe('journal_lines')
        ->and($plan['key'])->toBe('journal_lines_account_covering')
        ->and((string) $plan['Extra'])->not->toContain('Using join buffer');
});
