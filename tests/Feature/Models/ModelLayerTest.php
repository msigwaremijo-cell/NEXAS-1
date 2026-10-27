<?php

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAccountingFixtures;

uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
});

it('fills the public ULID the database requires on insert', function (): void {
    $company = Company::create([
        'name' => 'Second Tenant Ltd',
        'base_currency_code' => 'TZS',
    ]);

    expect($company->ulid)->toHaveLength(26)
        ->and($company->getRouteKeyName())->toBe('ulid')
        ->and(Company::where('ulid', $company->ulid)->exists())->toBeTrue();
});

it('casts typed columns to enums and money columns to strings', function (): void {
    $account = Account::findOrFail($this->cashAccountId);

    expect($account->account_type)->toBe(AccountType::Asset)
        ->and($account->normal_balance)->toBe(NormalBalance::Debit)
        ->and($account->is_cash)->toBeTrue();

    $journal = Journal::findOrFail($this->balancedJournal('JRN-MODEL-1'));

    expect($journal->journal_type->value)->toBe('general')
        ->and($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->posting_date->toDateString())->toBe('2026-03-15')
        ->and($journal->total_debit)->toBeString();
});

it('exposes the trigger-derived totals after a journal is posted', function (): void {
    $journalId = $this->balancedJournal('JRN-MODEL-2', '275.5000');

    $journal = Journal::findOrFail($journalId);
    expect($journal->isDraft())->toBeTrue();

    $this->postJournal($journalId);
    $journal->refresh();

    expect($journal->status)->toBe(JournalStatus::Posted)
        ->and($journal->isPosted())->toBeTrue()
        ->and($journal->total_debit)->toBe('275.5000')
        ->and($journal->total_credit)->toBe('275.5000')
        ->and($journal->isBalanced())->toBeTrue()
        ->and($journal->posted_at)->not->toBeNull();
});

it('reads the generated posting period columns but never writes them', function (): void {
    $journalId = $this->balancedJournal('JRN-MODEL-3');

    $line = JournalLine::where('journal_id', $journalId)->orderBy('line_no')->firstOrFail();

    expect($line->posting_year)->toBe(2026)
        ->and($line->posting_month)->toBe(3)
        ->and($line->isDebit())->toBeTrue()
        ->and($line->amount()->toDecimal())->toBe('100.0000')
        ->and((new JournalLine)->getFillable())->not->toContain('posting_year')
        ->and((new JournalLine)->getFillable())->not->toContain('posting_month');
});

it('keeps the header totals out of mass assignment', function (): void {
    $fillable = (new Journal)->getFillable();

    expect($fillable)->not->toContain('total_debit')
        ->and($fillable)->not->toContain('total_credit')
        ->and($fillable)->not->toContain('posted_at')
        ->and((new AuditLog)->getFillable())->not->toContain('hash')
        ->and((new AuditLog)->getFillable())->not->toContain('previous_hash');
});

it('treats the audit log as write once', function (): void {
    expect((new AuditLog)->getUpdatedAtColumn())->toBeNull();

    $log = AuditLog::create([
        'company_id' => $this->companyId,
        'action' => 'model.layer.probe',
        'auditable_type' => Journal::class,
        'auditable_id' => 1,
        'ip_address' => '127.0.0.1',
    ]);

    $log->refresh();

    expect($log->hash)->toHaveLength(64)
        ->and($log->previous_hash)->toBeNull()
        ->and($log->ulid)->toHaveLength(26);
});

it('soft deletes master data and leaves journals without a delete flag', function (): void {
    $account = Account::findOrFail($this->revenueAccountId);
    $account->delete();

    expect(Account::find($this->revenueAccountId))->toBeNull()
        ->and(Account::withTrashed()->find($this->revenueAccountId)->deleted_at)->not->toBeNull()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(Journal::class), true))->toBeFalse()
        ->and(in_array(SoftDeletes::class, class_uses_recursive(JournalLine::class), true))->toBeFalse();
});

it('resolves a tax rate from the dated rate table, not from code', function (): void {
    $taxCode = TaxCode::create([
        'company_id' => $this->companyId,
        'code' => 'VAT18',
        'name' => 'VAT standard rate',
        'tax_type' => 'vat',
        'applies_to' => 'both',
    ]);

    TaxRate::create([
        'company_id' => $this->companyId,
        'tax_code_id' => $taxCode->id,
        'rate' => '0.180000',
        'effective_from' => '2025-07-01',
    ]);

    expect($taxCode->rateOn('2026-03-15')->rate)->toBe('0.180000')
        ->and($taxCode->rateOn('2025-06-30'))->toBeNull();
});

it('knows whether a fiscal period still accepts postings', function (): void {
    $period = FiscalPeriod::findOrFail($this->fiscalPeriodId);

    expect($period->acceptsPostings())->toBeTrue()
        ->and($period->containsDate('2026-03-15'))->toBeTrue()
        ->and($period->containsDate('2026-04-01'))->toBeFalse();
});
