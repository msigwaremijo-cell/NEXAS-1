<?php

use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Exceptions\FiscalPeriodNotOpenException;
use App\Domain\Accounting\Exceptions\IdempotencyConflictException;
use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Exceptions\JournalStateException;
use App\Models\Journal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RunsJournalActions;

/*
 * The actions are exercised against a real MySQL schema, because half of what
 * they have to respect — the balance trigger, the period lock, the gapless
 * sequence — only exists in the database. RefreshDatabase is enough here: no
 * test in this file needs DDL.
 */
uses(RefreshDatabase::class, RunsJournalActions::class);

beforeEach(function () {
    $this->makeFixtures();
});

/*
|--------------------------------------------------------------------------
| CreateJournal
|--------------------------------------------------------------------------
*/

it('writes a draft with a gapless number and a period resolved from the date', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('500.0000'), memo: 'Opening sale'));

    expect($journal->journal_no)->toBe('JV-000001')
        ->and($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->journal_type)->toBe(JournalType::General)
        ->and((int) $journal->fiscal_year_id)->toBe($this->fiscalYearId)
        ->and((int) $journal->fiscal_period_id)->toBe($this->fiscalPeriodId)
        ->and($journal->currency_code)->toBe('TZS')
        ->and($journal->lines)->toHaveCount(2);

    // The number came out of the locked sequence, and the counter moved on.
    expect($journal->lines->pluck('debit')->all())->toBe(['500.0000', '0.0000'])
        ->and(DB::table('document_sequences')
            ->where('document_type', 'journal')
            ->value('next_number'))->toBe(2);
});

it('hands out consecutive numbers to consecutive drafts', function () {
    $first = $this->createViaAction($this->entry($this->balancedLines('10.0000')));
    $second = $this->createViaAction($this->entry($this->balancedLines('20.0000')));

    expect($first->journal_no)->toBe('JV-000001')
        ->and($second->journal_no)->toBe('JV-000002');
});

it('allows an unbalanced draft, because balance is a posting rule', function () {
    $journal = $this->createViaAction($this->entry([
        JournalLineData::debit($this->cashAccountId, '100.0000'),
        JournalLineData::credit($this->revenueAccountId, '60.0000'),
    ]));

    expect($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->lines)->toHaveCount(2);
});

it('refuses a header account, which must never carry a posting', function () {
    $headerId = $this->makeAccount('4000-H', 'Revenue Header', 'revenue', 'credit');
    DB::table('accounts')->where('id', $headerId)->update(['is_postable' => false]);

    expect(fn () => $this->createViaAction($this->entry([
        JournalLineData::debit($this->cashAccountId, '10.0000'),
        JournalLineData::credit($headerId, '10.0000'),
    ])))->toThrow(InvalidJournalException::class, 'header account');

    expect(DB::table('journals')->count())->toBe(0);
});

it('refuses an account that is not live for this company', function () {
    expect(fn () => $this->createViaAction($this->entry([
        JournalLineData::debit($this->cashAccountId, '10.0000'),
        JournalLineData::credit(999999, '10.0000'),
    ])))->toThrow(InvalidJournalException::class, 'not a live account');

    expect(DB::table('journals')->count())->toBe(0);
});

it('refuses a posting date that no fiscal period contains', function () {
    expect(fn () => $this->createViaAction(
        $this->entry($this->balancedLines('10.0000'), postingDate: '2027-05-01')
    ))->toThrow(FiscalPeriodNotOpenException::class, 'cannot be resolved');

    // The transaction rolled back: no journal and no half-opened sequence.
    expect(DB::table('journals')->count())->toBe(0)
        ->and(DB::table('document_sequences')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| PostJournal
|--------------------------------------------------------------------------
*/

it('posts a balanced journal and lets the trigger derive the header totals', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('750.0000')));

    $posted = $this->postViaAction($journal);

    expect($posted->status)->toBe(JournalStatus::Posted)
        ->and($posted->total_debit)->toBe('750.0000')
        ->and($posted->total_credit)->toBe('750.0000')
        ->and($posted->posted_at)->not->toBeNull()
        ->and($posted->row_version)->toBe(1)
        ->and($posted->lines)->toHaveCount(2);
});

it('refuses to post an unbalanced draft, and says so in words', function () {
    $journal = $this->createViaAction($this->entry([
        JournalLineData::debit($this->cashAccountId, '100.0000'),
        JournalLineData::credit($this->revenueAccountId, '60.0000'),
    ]));

    expect(fn () => $this->postViaAction($journal))
        ->toThrow(InvalidJournalException::class, 'not balanced');

    expect(DB::table('journals')->where('id', $journal->getKey())->value('status'))->toBe('draft');
});

it('refuses to post a journal with only one side', function () {
    $journal = $this->createViaAction($this->entry([
        JournalLineData::debit($this->cashAccountId, '10.0000'),
        JournalLineData::debit($this->revenueAccountId, '10.0000'),
    ]));

    expect(fn () => $this->postViaAction($journal))
        ->toThrow(InvalidJournalException::class, 'greater than zero');
});

it('refuses to post into a closed period, and into a closed year', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('100.0000')));

    DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->update(['status' => 'closed']);

    expect(fn () => $this->postViaAction($journal))
        ->toThrow(FiscalPeriodNotOpenException::class, 'cannot accept postings');

    expect(DB::table('journals')->where('id', $journal->getKey())->value('status'))->toBe('draft');

    DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->update(['status' => 'open']);
    DB::table('fiscal_years')->where('id', $this->fiscalYearId)->update(['status' => 'closed']);

    expect(fn () => $this->postViaAction($journal))
        ->toThrow(FiscalPeriodNotOpenException::class, 'fiscal year');
});

it('posts once even when the same idempotency key is presented twice', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('300.0000')));

    $first = $this->postViaAction($journal, 'post-key-1');
    $second = $this->postViaAction($journal, 'post-key-1');

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->status)->toBe(JournalStatus::Posted)
        ->and(DB::table('journals')->where('status', 'posted')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->value('status'))->toBe('completed')
        // The ledger was only touched once.
        ->and(DB::table('audit_logs')->where('action', 'journal.posted')->count())->toBe(1);
});

it('refuses to reuse an idempotency key for a different journal', function () {
    $first = $this->createViaAction($this->entry($this->balancedLines('100.0000')));
    $second = $this->createViaAction($this->entry($this->balancedLines('100.0000')));

    $this->postViaAction($first, 'shared-key');

    expect(fn () => $this->postViaAction($second, 'shared-key'))
        ->toThrow(IdempotencyConflictException::class, 'different request');

    expect(DB::table('journals')->where('id', $second->getKey())->value('status'))->toBe('draft');
});

it('refuses a posting built on a stale copy of the journal', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('100.0000')));

    expect(fn () => $this->postViaAction($journal, null, 99))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    expect(DB::table('journals')->where('id', $journal->getKey())->value('status'))->toBe('draft');
});

it('treats a second posting without a key as a no-op', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('100.0000')));

    $first = $this->postViaAction($journal);
    $second = $this->postViaAction($first);

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->status)->toBe(JournalStatus::Posted)
        ->and($second->row_version)->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'journal.posted')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| ReverseJournal
|--------------------------------------------------------------------------
*/

it('reverses a posted journal with a mirror-image entry', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('250.0000')));
    $posted = $this->postViaAction($journal);

    $reversal = $this->reverseViaAction($posted);

    expect($reversal->journal_type)->toBe(JournalType::Reversal)
        ->and($reversal->status)->toBe(JournalStatus::Posted)
        ->and((int) $reversal->reverses_journal_id)->toBe($posted->getKey())
        ->and($reversal->journal_no)->toBe('JV-000002');

    $original = Journal::query()->findOrFail($posted->getKey());

    expect($original->status)->toBe(JournalStatus::Reversed)
        ->and((int) $original->reversed_by_journal_id)->toBe($reversal->getKey());

    // The two entries net to zero on every account they touch.
    $net = DB::select(<<<'SQL'
        SELECT a.code,
               SUM(l.debit) - SUM(l.credit) AS movement
          FROM journal_lines l
          JOIN journals j ON j.id = l.journal_id
          JOIN accounts a ON a.id = l.account_id
         WHERE l.company_id = ?
           AND j.status IN ('posted', 'reversed')
         GROUP BY a.id, a.code
         ORDER BY a.code
    SQL, [$this->companyId]);

    expect($net)->toHaveCount(2);

    foreach ($net as $row) {
        expect((float) $row->movement)->toBe(0.0);
    }
});

it('refuses to reverse a journal that was never posted', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('100.0000')));

    expect(fn () => $this->reverseViaAction($journal))
        ->toThrow(JournalStateException::class, 'Only a posted journal can be reversed');
});

it('refuses to reverse the same journal twice', function () {
    $journal = $this->createViaAction($this->entry($this->balancedLines('100.0000')));
    $posted = $this->postViaAction($journal);

    $this->reverseViaAction($posted);

    $original = Journal::query()->findOrFail($posted->getKey());

    expect($original->status)->toBe(JournalStatus::Reversed);

    expect(fn () => $this->reverseViaAction($original))
        ->toThrow(JournalStateException::class, 'cannot be reversed');
});

/*
|--------------------------------------------------------------------------
| Trial balance through the actions
|--------------------------------------------------------------------------
*/

it('keeps the trial balance in agreement when journals go through the actions', function () {
    foreach (['111.1111', '222.2222', '333.3333'] as $amount) {
        $this->postViaAction($this->createViaAction($this->entry($this->balancedLines($amount))));
    }

    $totals = DB::selectOne(<<<'SQL'
        SELECT COALESCE(SUM(l.debit), 0)  AS total_debit,
               COALESCE(SUM(l.credit), 0) AS total_credit
          FROM journal_lines l
          JOIN journals j ON j.id = l.journal_id
         WHERE l.company_id = ? AND j.status = 'posted'
    SQL, [$this->companyId]);

    expect((string) $totals->total_debit)->toBe('666.6666')
        ->and((string) $totals->total_credit)->toBe('666.6666');
});
