<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RunsJournalActions;

// The return is a sum over the posted ledger, and every rule that decides what
// is in the ledger — the draft/posted distinction, the reversal mirror — lives
// in MySQL rather than in PHP, so the surface is exercised over the real schema.
uses(RefreshDatabase::class, RunsJournalActions::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // The two accounts a VAT code posts to, and which the return is a sum over.
    $this->outputVatAccountId = nxVatAccount($this->companyId, '2120', 'VAT Output', 'liability', 'credit');
    $this->inputVatAccountId = nxVatAccount($this->companyId, '1400', 'VAT Input', 'asset', 'debit');
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxVatMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A ledger account in a named company, written directly so a test can place one
 * in a company the caller is not a member of.
 */
function nxVatAccount(int $companyId, string $code, string $name, string $type, string $normal): int
{
    return DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => $name,
        'account_type' => $type,
        'normal_balance' => $normal,
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A tax code with one rate in force since 2015, so every fixture date resolves.
 */
function nxVatTaxCode(
    int $companyId,
    string $code,
    ?int $outputAccountId,
    ?int $inputAccountId,
    string $rate = '0.180000',
    string $taxType = 'vat',
): int {
    $taxCodeId = DB::table('tax_codes')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => $code.' test code',
        'tax_type' => $taxType,
        'applies_to' => 'both',
        'is_inclusive' => false,
        'is_recoverable' => $taxType === 'vat',
        'output_account_id' => $outputAccountId,
        'input_account_id' => $inputAccountId,
        'status' => 'active',
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('tax_rates')->insert([
        'company_id' => $companyId,
        'tax_code_id' => $taxCodeId,
        'rate' => $rate,
        'effective_from' => '2015-07-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $taxCodeId;
}

/**
 * A second tenant, with a fiscal period of its own, used to prove a period
 * cannot be named across companies.
 *
 * @return array{company_id: int, period_ulid: string}
 */
function nxVatForeignCompany(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'name' => 'Nexas Other Company Limited',
        'country_code' => 'TZ',
        'base_currency_code' => 'TZS',
        'fiscal_year_start_month' => 1,
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $yearId = DB::table('fiscal_years')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'name' => 'FY2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $periodUlid = (string) Str::ulid();

    DB::table('fiscal_periods')->insert([
        'ulid' => $periodUlid,
        'company_id' => $companyId,
        'fiscal_year_id' => $yearId,
        'period_no' => 3,
        'name' => 'March 2026',
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-31',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['company_id' => $companyId, 'period_ulid' => $periodUlid];
}

/**
 * A fiscal period, so a test can post into a month the fixtures do not install.
 */
function nxVatPeriod(
    int $companyId,
    int $fiscalYearId,
    int $periodNo,
    string $name,
    string $startsOn,
    string $endsOn,
): object {
    $ulid = (string) Str::ulid();

    DB::table('fiscal_periods')->insert([
        'ulid' => $ulid,
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'period_no' => $periodNo,
        'name' => $name,
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (object) ['ulid' => $ulid, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
}

/**
 * A customer row, written directly so an invoice can be raised against it.
 */
function nxVatCustomer(int $companyId, int $receivableAccountId, string $code = 'C-001'): int
{
    return DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A sale of $net plus $vat, carrying the tax on the ledger the way IssueInvoice
 * does: the code named on the revenue line and again on the tax line.
 *
 * @return array<int, JournalLineData>
 */
function nxVatSaleLines(
    int $receivableId,
    int $revenueId,
    int $outputVatId,
    int $taxCodeId,
    string $net,
    string $vat,
): array {
    return [
        JournalLineData::debit($receivableId, bcadd($net, $vat, 4), 'Sale'),
        new JournalLineData($revenueId, '0', $net, 'Sale net', taxCodeId: $taxCodeId),
        new JournalLineData($outputVatId, '0', $vat, 'VAT', taxCodeId: $taxCodeId, taxAmount: $vat),
    ];
}

/**
 * A purchase of $net plus $vat, carrying the tax on the ledger the way PostBill
 * does: the code named on the expense line and again on the input tax line.
 *
 * @return array<int, JournalLineData>
 */
function nxVatPurchaseLines(
    int $expenseId,
    int $inputVatId,
    int $payableId,
    int $taxCodeId,
    string $net,
    string $vat,
): array {
    return [
        new JournalLineData($expenseId, $net, '0', 'Purchase', taxCodeId: $taxCodeId),
        new JournalLineData($inputVatId, $vat, '0', 'Input VAT', taxCodeId: $taxCodeId, taxAmount: $vat),
        JournalLineData::credit($payableId, bcadd($net, $vat, 4), 'Supplier'),
    ];
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the return without a token', function () {
    $this->getJson('/api/v1/reports/vat-return')->assertUnauthorized();
});

it('lets an auditor read the return', function () {
    // The auditor holds report.view and nothing else, and a filed return is
    // exactly what an audit is read against.
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/reports/vat-return')->assertOk();
});

it('refuses a return for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxVatForeignCompany()['company_id'])
        ->getJson('/api/v1/reports/vat-return')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

/*
|--------------------------------------------------------------------------
| Output and input tax
|--------------------------------------------------------------------------
*/

it('reports the tax charged and the tax borne, and what is left to pay', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $vat = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '1000.0000', '180.0000'),
        reference: 'INV-000001',
        journalType: JournalType::Sales,
    )));

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatPurchaseLines($this->expenseAccountId, $this->inputVatAccountId, $this->payableAccountId, $vat, '500.0000', '90.0000'),
        reference: 'BILL-000001',
        journalType: JournalType::Purchase,
    )));

    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('currency_code', 'TZS')
        ->assertJsonPath('output_vat.total', '180.0000')
        ->assertJsonPath('input_vat.total', '90.0000')
        // 180 charged less 90 borne leaves 90 to hand over.
        ->assertJsonPath('net_vat', '90.0000')
        ->assertJsonPath('payable', '90.0000')
        ->assertJsonPath('credit_carried_forward', '0.0000')
        ->assertJsonPath('other_taxes.total', '0.0000')
        // The breakdown names the code that raised the tax and the account it
        // was posted to, which is what makes the figure checkable.
        ->assertJsonPath('output_vat.lines.0.tax_code', 'VAT18')
        ->assertJsonPath('output_vat.lines.0.rate', '0.180000')
        ->assertJsonPath('output_vat.lines.0.account_code', '2120')
        ->assertJsonPath('output_vat.lines.0.amount', '180.0000')
        ->assertJsonPath('input_vat.lines.0.tax_code', 'VAT18')
        ->assertJsonPath('input_vat.lines.0.account_code', '1400')
        ->assertJsonPath('input_vat.lines.0.amount', '90.0000');
});

it('carries a credit forward when the tax borne exceeds the tax charged', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $vat = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '100.0000', '18.0000'),
        journalType: JournalType::Sales,
    )));

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatPurchaseLines($this->expenseAccountId, $this->inputVatAccountId, $this->payableAccountId, $vat, '500.0000', '90.0000'),
        journalType: JournalType::Purchase,
    )));

    // Owed 90 on purchases and charged 18 on sales: nothing is payable, and
    // the 72 difference is what the next return carries forward.
    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('net_vat', '-72.0000')
        ->assertJsonPath('payable', '0.0000')
        ->assertJsonPath('credit_carried_forward', '72.0000');
});

it('keeps a draft journal out of the return', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $vat = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    // Written but never posted. Its lines are physically in journal_lines, so
    // a return that read the table rather than the ledger would count it.
    $this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '1000.0000', '180.0000'),
        journalType: JournalType::Sales,
    ));

    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('output_vat.total', '0.0000')
        ->assertJsonPath('output_vat.lines', []);
});

it('reads a reversal as it reads any other movement', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $vat = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    $journal = $this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '1000.0000', '180.0000'),
        journalType: JournalType::Sales,
    ));

    $this->postViaAction($journal);

    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('output_vat.total', '180.0000');

    // Correcting the sale is a mirror journal, not an edit. The tax it gives
    // back is a debit to the output account, so it reduces the return of the
    // period it is dated in without any special case here.
    $this->reverseViaAction($journal);

    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('output_vat.total', '0.0000')
        ->assertJsonPath('net_vat', '0.0000');
});

it('adds up two codes that share one control account separately', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Both codes settle into 2120. A return built from the account alone could
    // not tell them apart; reading the code off the ledger line can.
    $standard = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId, '0.180000');
    $reduced = nxVatTaxCode($this->companyId, 'VAT12', $this->outputVatAccountId, $this->inputVatAccountId, '0.120000');

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $standard, '1000.0000', '180.0000'),
        journalType: JournalType::Sales,
    )));

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $reduced, '500.0000', '60.0000'),
        journalType: JournalType::Sales,
    )));

    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('output_vat.total', '240.0000')
        ->assertJsonCount(2, 'output_vat.lines')
        ->assertJsonPath('output_vat.lines.0.tax_code', 'VAT12')
        ->assertJsonPath('output_vat.lines.0.rate', '0.120000')
        ->assertJsonPath('output_vat.lines.0.amount', '60.0000')
        ->assertJsonPath('output_vat.lines.0.account_code', '2120')
        ->assertJsonPath('output_vat.lines.1.tax_code', 'VAT18')
        ->assertJsonPath('output_vat.lines.1.amount', '180.0000')
        // Both codes are named on the account they share, which is what
        // explains two lines carrying the same account.
        ->assertJsonPath('output_vat.lines.1.account_tax_codes', ['VAT12', 'VAT18']);
});

it('reports a tax that is not VAT under other taxes', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $vat = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    $withholdingAccountId = nxVatAccount($this->companyId, '2140', 'Withholding Tax Payable', 'liability', 'credit');
    $withholding = nxVatTaxCode($this->companyId, 'WHT10', $withholdingAccountId, null, '0.100000', 'withholding');

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '1000.0000', '180.0000'),
        journalType: JournalType::Sales,
    )));

    // Withholding tax is deducted from a payment rather than added to a line,
    // so it is not posted by the bill. Whatever reaches its account is reported
    // here, apart from VAT, because none of it is claimed back as input tax.
    $this->postViaAction($this->createViaAction($this->entry([
        JournalLineData::debit($this->expenseAccountId, '1000.0000', 'Consulting'),
        JournalLineData::credit($this->payableAccountId, '900.0000', 'Supplier'),
        new JournalLineData($withholdingAccountId, '0', '100.0000', 'WHT', taxCodeId: $withholding, taxAmount: '100.0000'),
    ], journalType: JournalType::Purchase)));

    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        // VAT is untouched by the withholding, and the withholding is not VAT.
        ->assertJsonPath('output_vat.total', '180.0000')
        ->assertJsonPath('input_vat.total', '0.0000')
        ->assertJsonPath('net_vat', '180.0000')
        ->assertJsonPath('other_taxes.total', '100.0000')
        ->assertJsonPath('other_taxes.lines.0.tax_code', 'WHT10')
        ->assertJsonPath('other_taxes.lines.0.tax_type', 'withholding')
        ->assertJsonPath('other_taxes.lines.0.amount', '100.0000');
});

/*
|--------------------------------------------------------------------------
| The window
|--------------------------------------------------------------------------
*/

it('brackets the return by the fiscal period it is asked for', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $vat = nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    $march = DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->value('ulid');
    $april = nxVatPeriod($this->companyId, $this->fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30');

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '1000.0000', '180.0000'),
        journalType: JournalType::Sales,
    )));

    $this->postViaAction($this->createViaAction($this->entry(
        nxVatSaleLines($this->receivableAccountId, $this->revenueAccountId, $this->outputVatAccountId, $vat, '2000.0000', '360.0000'),
        postingDate: '2026-04-10',
        journalType: JournalType::Sales,
    )));

    $this->getJson('/api/v1/reports/vat-return?fiscal_period='.$march)
        ->assertOk()
        ->assertJsonPath('from', '2026-03-01')
        ->assertJsonPath('to', '2026-03-31')
        ->assertJsonPath('fiscal_period.name', 'March 2026')
        ->assertJsonPath('fiscal_period.period_no', 3)
        ->assertJsonPath('output_vat.total', '180.0000');

    $this->getJson('/api/v1/reports/vat-return?fiscal_period='.$april->ulid)
        ->assertOk()
        ->assertJsonPath('from', '2026-04-01')
        ->assertJsonPath('to', '2026-04-30')
        ->assertJsonPath('output_vat.total', '360.0000');

    // The whole ledger, when no window is named.
    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('from', null)
        ->assertJsonPath('output_vat.total', '540.0000');
});

it('refuses a window that runs backwards', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->getJson('/api/v1/reports/vat-return?from=2026-03-31&to=2026-03-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

it('answers not found for a fiscal period in another company', function () {
    $foreign = nxVatForeignCompany();

    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->getJson('/api/v1/reports/vat-return?fiscal_period='.$foreign['period_ulid'])
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| What the sales and purchase documents actually post
|--------------------------------------------------------------------------
*/

it('reports the tax a taxed invoice posts, code by code', function () {
    Sanctum::actingAs(nxVatMember($this->companyId, AccessMatrix::ROLE_OWNER));

    nxVatTaxCode($this->companyId, 'VAT18', $this->outputVatAccountId, $this->inputVatAccountId);

    $customerId = nxVatCustomer($this->companyId, $this->receivableAccountId);

    $draft = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '1000.0000',
            'tax_code_id' => DB::table('tax_codes')->where('company_id', $this->companyId)->where('code', 'VAT18')->value('id'),
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertOk();

    // The invoice was priced at 1000 plus 18% VAT, so the return has to show
    // the 180 the issue actually posted — without this test knowing how the
    // posting is built.
    $this->getJson('/api/v1/reports/vat-return')
        ->assertOk()
        ->assertJsonPath('output_vat.total', '180.0000')
        ->assertJsonPath('output_vat.lines.0.tax_code', 'VAT18')
        ->assertJsonPath('output_vat.lines.0.account_code', '2120')
        ->assertJsonPath('net_vat', '180.0000');
});
