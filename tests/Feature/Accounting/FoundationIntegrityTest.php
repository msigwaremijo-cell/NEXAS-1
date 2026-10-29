<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccountingFixtures;

uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function () {
    $this->makeFixtures();
});

/**
 * Foundation test 1: an unbalanced journal cannot be posted.
 */
it('rejects an unbalanced journal at the moment of posting', function () {
    $journalId = $this->makeJournal('JRN-UNBALANCED');
    $this->addLine($journalId, 1, $this->cashAccountId, '100.0000', '0');
    $this->addLine($journalId, 2, $this->revenueAccountId, '0', '60.0000');

    expect(fn () => $this->postJournal($journalId))
        ->toThrow(QueryException::class, 'not balanced');

    expect(DB::table('journals')->where('id', $journalId)->value('status'))->toBe('draft');
});

it('rejects a journal that has no lines, or only one', function () {
    $empty = $this->makeJournal('JRN-EMPTY');

    expect(fn () => $this->postJournal($empty))
        ->toThrow(QueryException::class, 'at least two lines');

    $single = $this->makeJournal('JRN-SINGLE');
    $this->addLine($single, 1, $this->cashAccountId, '10.0000', '0');

    expect(fn () => $this->postJournal($single))
        ->toThrow(QueryException::class, 'at least two lines');
});

/**
 * Foundation test 2: a posted journal is immutable.
 */
it('freezes a posted journal and its lines against every kind of change', function () {
    $journalId = $this->balancedJournal('JRN-POSTED');
    $this->postJournal($journalId);

    $posted = DB::table('journals')->where('id', $journalId)->first();
    expect($posted->status)->toBe('posted')
        ->and((float) $posted->total_debit)->toBe(100.0)
        ->and((float) $posted->total_credit)->toBe(100.0)
        ->and($posted->posted_at)->not->toBeNull();

    $edit = fn (array $values) => DB::table('journals')->where('id', $journalId)->update($values);
    $lineIds = DB::table('journal_lines')->where('journal_id', $journalId)->pluck('id');

    expect(fn () => $edit(['memo' => 'quietly rewritten']))->toThrow(QueryException::class, 'immutable');
    expect(fn () => $edit(['posting_date' => '2026-04-01']))->toThrow(QueryException::class, 'immutable');
    expect(fn () => $edit(['total_debit' => 1]))->toThrow(QueryException::class, 'immutable');
    expect(fn () => $edit(['status' => 'draft']))->toThrow(QueryException::class, 'immutable');
    expect(fn () => DB::table('journals')->where('id', $journalId)->delete())
        ->toThrow(QueryException::class, 'cannot be deleted');

    expect(fn () => DB::table('journal_lines')->where('id', $lineIds[0])->update(['debit' => '999.0000']))
        ->toThrow(QueryException::class, 'immutable');
    expect(fn () => DB::table('journal_lines')->where('id', $lineIds[0])->delete())
        ->toThrow(QueryException::class, 'cannot be deleted');
    expect(fn () => $this->addLine($journalId, 3, $this->cashAccountId, '1.0000', '0'))
        ->toThrow(QueryException::class, 'while the journal is a draft');

    // Nothing leaked through.
    expect(DB::table('journals')->where('id', $journalId)->value('memo'))->toBeNull()
        ->and(DB::table('journal_lines')->where('journal_id', $journalId)->count())->toBe(2);
});

it('allows a draft journal to be edited and deleted', function () {
    $journalId = $this->balancedJournal('JRN-DRAFT');
    $lineIds = DB::table('journal_lines')->where('journal_id', $journalId)->pluck('id');

    DB::table('journal_lines')->where('id', $lineIds[0])->update(['debit' => '150.0000']);
    DB::table('journal_lines')->where('id', $lineIds[1])->delete();
    DB::table('journal_lines')->where('id', $lineIds[0])->delete();
    DB::table('journals')->where('id', $journalId)->delete();

    expect(DB::table('journals')->where('id', $journalId)->exists())->toBeFalse();
});

/**
 * Foundation test 3: posting into a closed period fails.
 */
it('blocks posting into a closed or locked fiscal period', function () {
    $journalId = $this->balancedJournal('JRN-CLOSED-PERIOD');

    DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->update(['status' => 'closed']);

    expect(fn () => $this->postJournal($journalId))
        ->toThrow(QueryException::class, 'not open');

    DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->update(['status' => 'locked']);

    expect(fn () => $this->postJournal($journalId))
        ->toThrow(QueryException::class, 'not open');

    expect(DB::table('journals')->where('id', $journalId)->value('status'))->toBe('draft');
});

it('blocks posting when the fiscal year itself is closed', function () {
    $journalId = $this->balancedJournal('JRN-CLOSED-YEAR');

    DB::table('fiscal_years')->where('id', $this->fiscalYearId)->update(['status' => 'closed']);

    expect(fn () => $this->postJournal($journalId))
        ->toThrow(QueryException::class, 'fiscal year is not open');
});

/**
 * Foundation: the cash flow category is a closed list the schema enforces.
 *
 * Four words the cash flow statement can classify, plus null for "nobody has
 * said yet". Anything else is refused by MySQL rather than stored and then
 * quietly skipped by the report.
 */
it('refuses a cash flow category outside the set at the database level', function () {
    expect(fn () => DB::table('accounts')
        ->where('id', $this->cashAccountId)
        ->update(['cash_flow_category' => 'operating']))->not->toThrow(QueryException::class);

    // Nullable, because "not classified yet" is a real and reportable state.
    expect(fn () => DB::table('accounts')
        ->where('id', $this->cashAccountId)
        ->update(['cash_flow_category' => null]))->not->toThrow(QueryException::class);

    expect(fn () => DB::table('accounts')
        ->where('id', $this->cashAccountId)
        ->update(['cash_flow_category' => 'banana']))
        ->toThrow(QueryException::class, 'chk_accounts_cash_flow_category');
});

/**
 * Foundation test 4: the trial balance totals agree.
 */
it('keeps the trial balance in agreement after a batch of postings', function () {
    $amounts = ['100.0000', '275.5000', '19.9900', '1000.0000'];

    foreach ($amounts as $index => $amount) {
        $this->postJournal($this->balancedJournal('JRN-TB-'.$index, $amount));
    }

    // A rejected posting must not disturb the totals.
    $bad = $this->makeJournal('JRN-TB-REJECTED');
    $this->addLine($bad, 1, $this->cashAccountId, '50.0000', '0');
    $this->addLine($bad, 2, $this->revenueAccountId, '0', '40.0000');
    expect(fn () => $this->postJournal($bad))->toThrow(QueryException::class);

    $balances = DB::select(<<<'SQL'
        SELECT a.code,
               COALESCE(SUM(l.debit), 0)  AS total_debit,
               COALESCE(SUM(l.credit), 0) AS total_credit
          FROM journal_lines l
          JOIN journals j ON j.id = l.journal_id
          JOIN accounts a ON a.id = l.account_id
         WHERE l.company_id = ? AND j.status = 'posted'
         GROUP BY a.id, a.code
         ORDER BY a.code
    SQL, [$this->companyId]);

    expect($balances)->toHaveCount(2);

    $totalDebits = array_sum(array_map(fn ($row) => (float) $row->total_debit, $balances));
    $totalCredits = array_sum(array_map(fn ($row) => (float) $row->total_credit, $balances));

    expect(round($totalDebits, 4))->toBe(1395.49)
        ->and(round($totalCredits, 4))->toBe(1395.49)
        ->and(round($totalDebits, 4))->toBe(round($totalCredits, 4));

    expect(DB::table('journals')->where('status', 'posted')->count())->toBe(4);
});
