<?php

use App\Domain\Accounting\Enums\AccountType;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

/**
 * Batch F proof: the seeded demo tenant must satisfy the same database rules a
 * live tenant does. These assertions read the seeded rows back out of MySQL
 * rather than trusting the seeder's intent.
 */
it('seeds the currencies with TZS available as the reporting currency', function () {
    expect(DB::table('currencies')->where('code', 'TZS')->exists())->toBeTrue()
        ->and(DB::table('currencies')->where('is_active', true)->count())->toBe(5);
});

it('seeds the demo company on TZS with a January fiscal start', function () {
    $company = DB::table('companies')->sole();

    expect($company->base_currency_code)->toBe('TZS')
        ->and($company->fiscal_year_start_month)->toBe(1)
        ->and($company->timezone)->toBe('Africa/Dar_es_Salaam')
        ->and($company->status)->toBe('active')
        ->and(DB::table('branches')->where('company_id', $company->id)->where('is_default', true)->count())->toBe(1);
});

it('gives every account a ULID, a parent chain and a depth that matches it', function () {
    $accounts = DB::table('accounts')->get()->keyBy('id');

    expect($accounts)->toHaveCount(70)
        ->and($accounts->where('ulid', null))->toHaveCount(0)
        ->and($accounts->where('is_postable', true))->toHaveCount(60)
        ->and($accounts->where('is_postable', false))->toHaveCount(10);

    foreach ($accounts as $account) {
        $ancestors = [];
        $cursor = $account;
        $guard = 0;

        while ($cursor->parent_id !== null && $guard++ < 20) {
            $parent = $accounts[$cursor->parent_id];
            array_unshift($ancestors, $parent->code);
            $cursor = $parent;
        }

        $expectedPath = $ancestors === [] ? null : implode('/', $ancestors);

        expect($account->depth)->toBe(count($ancestors), "depth of account {$account->code}")
            ->and($account->path)->toBe($expectedPath, "path of account {$account->code}");
    }
});

it('flags exactly the cash and bank ledger accounts', function () {
    $cash = DB::table('accounts')->where('is_cash', true)->orderBy('code')->pluck('code')->all();
    $bank = DB::table('accounts')->where('is_bank', true)->orderBy('code')->pluck('code')->all();

    expect($cash)->toBe(['1110', '1140'])
        ->and($bank)->toBe(['1120', '1130']);
});

it('derives each account normal balance from its type except the documented contra accounts', function () {
    $overrides = ['1210', '1590', '3300', '4120', '5120'];

    foreach (DB::table('accounts')->get() as $account) {
        $expected = AccountType::from($account->account_type)->normalBalance()->value;

        if (in_array($account->code, $overrides, true)) {
            continue;
        }

        expect($account->normal_balance)->toBe($expected, "normal balance of account {$account->code}");
    }

    expect(DB::table('accounts')->whereIn('code', $overrides)->pluck('normal_balance', 'code')->all())
        ->toBe(['1210' => 'credit', '1590' => 'credit', '3300' => 'debit', '4120' => 'debit', '5120' => 'credit']);
});

it('opens a fiscal year with twelve open monthly periods inside the 1..12 range', function () {
    $year = DB::table('fiscal_years')->sole();

    expect($year->starts_on)->toBe('2026-01-01')
        ->and($year->ends_on)->toBe('2026-12-31')
        ->and($year->status)->toBe('open');

    $periods = DB::table('fiscal_periods')->orderBy('period_no')->get();

    expect($periods)->toHaveCount(12)
        ->and($periods->pluck('period_no')->all())->toBe(range(1, 12))
        ->and($periods->pluck('status')->unique()->all())->toBe(['open']);

    foreach ($periods as $period) {
        expect($period->fiscal_year_id)->toBe($year->id)
            ->and($period->ends_on >= $period->starts_on)->toBeTrue()
            ->and($period->ulid)->not->toBeNull();
    }
});

it('creates one gapless counter per document type for the fiscal year', function () {
    $year = DB::table('fiscal_years')->sole();

    $sequences = DB::table('document_sequences')->orderBy('document_type')->get();

    expect($sequences->pluck('document_type')->all())
        ->toBe(['bill', 'credit_note', 'invoice', 'journal', 'payment']);

    foreach ($sequences as $sequence) {
        expect($sequence->fiscal_year_id)->toBe($year->id)
            ->and($sequence->next_number)->toBe(1)
            ->and($sequence->padding)->toBeGreaterThanOrEqual(1)
            ->and($sequence->padding)->toBeLessThanOrEqual(12);
    }
});

it('stores tax rates as dated rows and resolves them by document date', function () {
    expect(DB::table('tax_codes')->count())->toBe(4)
        ->and(DB::table('tax_rates')->count())->toBe(4);

    foreach (TaxCode::query()->with('rates')->get() as $taxCode) {
        expect($taxCode->rates)->not->toBeEmpty("tax code {$taxCode->code} has no rate row");
    }

    $vat = TaxCode::query()->where('code', 'VAT18')->sole();

    expect($vat->rateOn('2026-03-15')?->rate)->toBe('0.180000')
        ->and($vat->rateOn('2015-06-30'))->toBeNull()
        ->and($vat->outputAccount?->code)->toBe('2120')
        ->and($vat->inputAccount?->code)->toBe('1400');

    $withholding = TaxCode::query()->where('code', 'WHT10')->sole();

    expect($withholding->applies_to->value)->toBe('purchases')
        ->and($withholding->tax_type->isDeducted())->toBeTrue();
});

it('seeds a demo operator attached to the demo company', function () {
    $company = DB::table('companies')->sole();
    $user = DB::table('users')->sole();

    expect($user->email)->toBe('admin@nexas.co.tz')
        ->and($user->status)->toBe('active')
        ->and($user->ulid)->not->toBeNull()
        ->and(DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->where('is_default', true)->exists())->toBeTrue();
});

it('is idempotent: seeding twice leaves one of every row', function () {
    $this->seed();

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('branches')->count())->toBe(1)
        ->and(DB::table('accounts')->count())->toBe(70)
        ->and(DB::table('fiscal_periods')->count())->toBe(12)
        ->and(DB::table('document_sequences')->count())->toBe(5)
        ->and(DB::table('tax_codes')->count())->toBe(4)
        ->and(DB::table('tax_rates')->count())->toBe(4)
        ->and(DB::table('users')->count())->toBe(1)
        ->and(DB::table('company_user')->count())->toBe(1);
});
