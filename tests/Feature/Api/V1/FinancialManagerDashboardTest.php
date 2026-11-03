<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The Financial Manager dashboard is a composition of the reports, so it is
// exercised over a real schema: which journals count as posted, what an ageing
// bucket is measured from and whether a bill carries a liability are all decided
// by triggers and columns MySQL owns, not by PHP.
//
// The point of these tests is the one the dashboard stands or falls on: every
// figure on the screen is the ledger's, reached through the same services the
// report endpoints use, and the reconciliation block says out loud when two
// readings of the same books disagree rather than making them agree.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // An export of the dashboard is a report in a file, so the disk is faked
    // here as it is in the export suite: the same put/read code path runs
    // without writing into the real store.
    Storage::fake('exports');
});

/**
 * A member of a company, holding exactly one company-scoped role. A null role
 * leaves them a member with the books in view and no right to read them.
 */
function nxDashMember(int $companyId, ?string $role = null): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    if ($role !== null) {
        setPermissionsTeamId($companyId);
        $user->syncRoles([$role]);
        setPermissionsTeamId(null);
    }

    return $user;
}

/**
 * A second tenant. Nothing in the caller's company can be reached by naming
 * something in another one, and the dashboard is asked for through the same
 * X-Company-Id header as every other endpoint.
 */
function nxDashCompany(): int
{
    $now = now();

    return DB::table('companies')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'name' => 'Nexas Other Company Limited',
        'country_code' => 'TZ',
        'base_currency_code' => 'TZS',
        'fiscal_year_start_month' => 1,
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/**
 * A customer row, written directly so a test can pin the receivable control
 * account its invoices post into and the terms that decide their due date.
 */
function nxDashCustomer(int $companyId, int $receivableAccountId, string $code = 'C-001', int $terms = 30): int
{
    return DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Acme Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => $terms,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A supplier row, written directly for the same reason: the payable control
 * account its bills post into is what the payables ageing is tied out against.
 */
function nxDashSupplier(int $companyId, int $payableAccountId, string $code = 'S-001', int $terms = 45): int
{
    return DB::table('suppliers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Kilimanjaro Supplies Limited',
        'currency_code' => 'TZS',
        'payable_account_id' => $payableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => $terms,
        'status' => 'active',
        'withholding_agent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The KPI cards of a dashboard payload, keyed by the measure they state, so a
 * test reads the figure by name rather than by position.
 *
 * @param  array<string, mixed>  $payload
 * @return Collection<string, array<string, mixed>>
 */
function nxDashCards(array $payload): Collection
{
    return collect($payload['sections']['kpis']['data']['cards'] ?? [])->keyBy('key');
}

/**
 * The reconciliation checks of a dashboard payload, keyed by the tie they make.
 *
 * @param  array<string, mixed>  $payload
 * @return Collection<string, array<string, mixed>>
 */
function nxDashChecks(array $payload): Collection
{
    return collect($payload['sections']['reconciliation']['data']['checks'] ?? [])->keyBy('key');
}

/**
 * The dashboard over one window, as the caller the test has already acted as.
 *
 * @return array<string, mixed>
 */
function nxDashWindowFor(string $from = '2026-03-01', string $to = '2026-03-31'): array
{
    return test()->getJson('/api/v1/reports/dashboard?from='.$from.'&to='.$to)
        ->assertOk()
        ->json();
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the dashboard without a token', function () {
    $this->getJson('/api/v1/reports/dashboard')->assertUnauthorized();
});

it('refuses a member who holds no role in the company', function () {
    // Membership alone is not the right to read the books: the dashboard is
    // guarded by the report read right like every statement it is made of.
    Sanctum::actingAs(nxDashMember($this->companyId));

    $this->getJson('/api/v1/reports/dashboard?from=2026-03-01&to=2026-03-31')->assertForbidden();
});

it('lets every seeded role read the dashboard', function (string $role) {
    // Every role in the matrix holds report.view — reading the books is the one
    // thing they all have — so every one of them must get an answer.
    Sanctum::actingAs(nxDashMember($this->companyId, $role));

    $this->getJson('/api/v1/reports/dashboard?from=2026-03-01&to=2026-03-31')->assertOk();
})->with([
    AccessMatrix::ROLE_OWNER,
    AccessMatrix::ROLE_ACCOUNTANT,
    AccessMatrix::ROLE_FINANCE_OFFICER,
    AccessMatrix::ROLE_CASHIER,
    AccessMatrix::ROLE_INVENTORY_MANAGER,
    AccessMatrix::ROLE_AUDITOR,
    AccessMatrix::ROLE_READ_ONLY,
    AccessMatrix::ROLE_FINANCIAL_MANAGER,
]);

/*
|--------------------------------------------------------------------------
| The executive measures
|--------------------------------------------------------------------------
*/

it('states the eight executive measures from the posted ledger', function () {
    // 500 earned into cash, 200 spent out of it: revenue, expense and the net
    // between them all come off the same two journals.
    $income = $this->makeJournal('JV-000001');
    $this->addLine($income, 1, $this->cashAccountId, '500.0000', '0');
    $this->addLine($income, 2, $this->revenueAccountId, '0', '500.0000');
    $this->postJournal($income);

    $spend = $this->makeJournal('JV-000002');
    $this->addLine($spend, 1, $this->expenseAccountId, '200.0000', '0');
    $this->addLine($spend, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($spend);

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $cards = nxDashCards(nxDashWindowFor());

    // The eight measures, in the order a reader meets them.
    expect($cards->keys()->all())->toBe([
        'revenue',
        'expenses',
        'net_profit',
        'profit_margin',
        'cash_and_bank',
        'accounts_receivable',
        'accounts_payable',
        'inventory_valuation',
    ]);

    expect($cards['revenue']['value'])->toBe('500.0000')
        ->and($cards['expenses']['value'])->toBe('200.0000')
        ->and($cards['net_profit']['value'])->toBe('300.0000')
        // Net profit over revenue, stated as a percentage.
        ->and($cards['profit_margin']['value'])->toBe('60.00')
        ->and($cards['profit_margin']['unit'])->toBe('percent')
        ->and($cards['cash_and_bank']['value'])->toBe('300.0000')
        ->and($cards['accounts_receivable']['value'])->toBe('0.0000')
        ->and($cards['accounts_payable']['value'])->toBe('0.0000')
        ->and($cards['inventory_valuation']['value'])->toBe('0.0000');
});

it('carries a card through to the report it was read from', function () {
    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $cards = nxDashCards(nxDashWindowFor());

    // A figure a reader cannot reach the report behind is a figure they have to
    // take on trust, so every card names the report and the endpoint that hold
    // its working.
    expect($cards['revenue']['report']['key'])->toBe('profit_and_loss')
        ->and($cards['revenue']['report']['api'])->toBe('/api/v1/reports/profit-and-loss')
        ->and($cards['cash_and_bank']['report']['key'])->toBe('cash_position')
        ->and($cards['accounts_receivable']['report']['key'])->toBe('receivables_ageing')
        ->and($cards['accounts_payable']['report']['key'])->toBe('payables_ageing')
        ->and($cards['inventory_valuation']['report']['key'])->toBe('stock_valuation');
});

/*
|--------------------------------------------------------------------------
| Only the posted ledger counts
|--------------------------------------------------------------------------
*/

it('keeps a draft journal out of every measure', function () {
    $this->postJournal($this->balancedJournal('JV-000001', '300.0000'));

    // A draft's lines are physically in journal_lines. They have not reached
    // the ledger, so no figure on the dashboard may count them.
    $this->balancedJournal('JV-000002', '900.0000');

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $payload = nxDashWindowFor();
    $cards = nxDashCards($payload);
    $checks = nxDashChecks($payload);

    expect($cards['revenue']['value'])->toBe('300.0000')
        ->and($cards['cash_and_bank']['value'])->toBe('300.0000')
        // The trial balance agrees because the draft is on neither side of it.
        ->and($checks['trial_balance']['actual'])->toBe('300.0000')
        ->and($checks['trial_balance']['expected'])->toBe('300.0000')
        ->and($checks['trial_balance']['reconciled'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Reconciliation with the ledger
|--------------------------------------------------------------------------
*/

it('reconciles the trial balance, the income statement and the cash position against the ledger', function () {
    $this->postJournal($this->balancedJournal('JV-000001', '300.0000'));

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $payload = nxDashWindowFor();
    $checks = nxDashChecks($payload);

    expect($payload['sections']['reconciliation']['data']['reconciled'])->toBeTrue();

    expect($checks['trial_balance']['reconciled'])->toBeTrue()
        ->and($checks['trial_balance']['difference'])->toBe('0.0000')
        ->and($checks['trial_balance']['actual'])->toBe($checks['trial_balance']['expected'])
        // The dashboard's net profit is the same figure reached from the trial
        // balance's revenue and expense accounts.
        ->and($checks['income_statement']['reconciled'])->toBeTrue()
        ->and($checks['income_statement']['actual'])->toBe($checks['income_statement']['expected'])
        // And what the cash position report says is what the cash and bank
        // accounts hold.
        ->and($checks['cash_position']['reconciled'])->toBeTrue()
        ->and($checks['cash_position']['actual'])->toBe('300.0000')
        ->and($checks['cash_position']['expected'])->toBe('300.0000');
});

it('reconciles the receivables ageing with the receivable control account', function () {
    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxDashCustomer($this->companyId, $this->receivableAccountId);

    $draft = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertOk();

    $payload = nxDashWindowFor();
    $checks = nxDashChecks($payload);

    // The ageing and the control account are reached by different routes — the
    // documents, and the ledger — and have to agree.
    expect($checks['receivables_ageing']['expected'])->toBe('300.0000')
        ->and($checks['receivables_ageing']['actual'])->toBe('300.0000')
        ->and($checks['receivables_ageing']['difference'])->toBe('0.0000')
        ->and($checks['receivables_ageing']['reconciled'])->toBeTrue()
        // And the KPI card states the same figure the ageing report does.
        ->and(nxDashCards($payload)['accounts_receivable']['value'])->toBe('300.0000');
});

it('reconciles the payables ageing with the payable control account', function () {
    $accountant = nxDashMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);
    $approver = nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCE_OFFICER);

    Sanctum::actingAs($accountant);

    $supplierId = nxDashSupplier($this->companyId, $this->payableAccountId);

    $draft = $this->postJson('/api/v1/bills', [
        'supplier_id' => $supplierId,
        'bill_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Office stationery',
            'account_id' => $this->expenseAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/submit')->assertOk();

    // A bill may not be approved by the member who entered it.
    Sanctum::actingAs($approver);
    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/approve')->assertOk();
    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/post')->assertOk();

    $payload = nxDashWindowFor();
    $checks = nxDashChecks($payload);

    // A payable is credit-normal, so the control account is stated as what the
    // company owes: the ledger balance negated, against the ageing total.
    expect($checks['payables_ageing']['expected'])->toBe('300.0000')
        ->and($checks['payables_ageing']['actual'])->toBe('300.0000')
        ->and($checks['payables_ageing']['difference'])->toBe('0.0000')
        ->and($checks['payables_ageing']['reconciled'])->toBeTrue()
        ->and(nxDashCards($payload)['accounts_payable']['value'])->toBe('300.0000');
});

it('reports a difference between the ageing and the ledger rather than adjusting it away', function () {
    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxDashCustomer($this->companyId, $this->receivableAccountId);

    $draft = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertOk();

    // Money taken off the control account without the document being settled:
    // the ledger now says 200 is owed and the invoice still says 300. That is
    // the finding, and it has to be reported as it stands.
    $contra = $this->makeJournal('JV-900001');
    $this->addLine($contra, 1, $this->revenueAccountId, '100.0000', '0');
    $this->addLine($contra, 2, $this->receivableAccountId, '0', '100.0000');
    $this->postJournal($contra);

    $payload = nxDashWindowFor();
    $checks = nxDashChecks($payload);

    expect($checks['receivables_ageing']['actual'])->toBe('300.0000')
        ->and($checks['receivables_ageing']['expected'])->toBe('200.0000')
        ->and($checks['receivables_ageing']['difference'])->toBe('100.0000')
        ->and($checks['receivables_ageing']['reconciled'])->toBeFalse();

    // The ledger itself is still sound, so the trial balance and the income
    // statement agree: the discrepancy is between the documents and the control
    // account, not an unbalanced journal.
    expect($checks['trial_balance']['reconciled'])->toBeTrue()
        ->and($checks['income_statement']['reconciled'])->toBeTrue();

    $reconciliation = $payload['sections']['reconciliation']['data'];

    expect($reconciliation['reconciled'])->toBeFalse()
        ->and($reconciliation['note'])->toContain('never adjusted away');
});

/*
|--------------------------------------------------------------------------
| The window the dashboard is read over
|--------------------------------------------------------------------------
*/

it('applies the period filter to the measures', function () {
    // Two postings in one month, and a month that contains both.
    $this->postingDate = '2026-03-10';
    $this->postJournal($this->balancedJournal('JV-000001', '100.0000'));

    $this->postingDate = '2026-03-25';
    $this->postJournal($this->balancedJournal('JV-000002', '250.0000'));

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $early = nxDashWindowFor('2026-03-01', '2026-03-15');
    $late = nxDashWindowFor('2026-03-16', '2026-03-31');
    $whole = nxDashWindowFor('2026-03-01', '2026-03-31');

    expect(nxDashCards($early)['revenue']['value'])->toBe('100.0000')
        ->and(nxDashCards($late)['revenue']['value'])->toBe('250.0000')
        // The two halves are the whole month: the window is really applied and
        // the figures are really the ledger's, cut to the dates asked for.
        ->and(nxDashCards($whole)['revenue']['value'])->toBe('350.0000');

    // And the window the dashboard says it used is the one that was asked for.
    expect($whole['window']['from'])->toBe('2026-03-01')
        ->and($whole['window']['to'])->toBe('2026-03-31')
        ->and($whole['window']['basis'])->toBe('custom')
        ->and($whole['window']['comparison_basis'])->toBe('equal_length_window_immediately_before')
        ->and($whole['window']['prior']['to'])->toBe('2026-02-28');
});

it('refuses a window that runs backwards', function () {
    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->getJson('/api/v1/reports/dashboard?from=2026-03-31&to=2026-03-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

/*
|--------------------------------------------------------------------------
| One company at a time
|--------------------------------------------------------------------------
*/

it('refuses a dashboard named for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->withHeader('X-Company-Id', (string) nxDashCompany())
        ->getJson('/api/v1/reports/dashboard?from=2026-03-01&to=2026-03-31')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

it('reads one company at a time and never leaks the other', function () {
    // The caller's own company holds 400 of revenue.
    $this->postJournal($this->balancedJournal('JV-000001', '400.0000'));

    $member = nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER);

    // A second tenant the same member also belongs to, with no books of its
    // own. Roles are created per company present when the seeder ran, so the
    // seeder is run again before a role can be held inside it.
    $otherCompanyId = nxDashCompany();

    $this->seed(RoleAndPermissionSeeder::class);

    $member->companies()->attach($otherCompanyId, ['is_default' => false, 'status' => 'active']);

    setPermissionsTeamId($otherCompanyId);
    $member->syncRoles([AccessMatrix::ROLE_FINANCIAL_MANAGER]);
    setPermissionsTeamId(null);

    Sanctum::actingAs($member);

    $mine = nxDashWindowFor();

    expect($mine['company']['id'])->toBe($this->companyId)
        ->and(nxDashCards($mine)['revenue']['value'])->toBe('400.0000');

    // The same request, pointed at the other company, reports that company's
    // books and never the caller's.
    $theirs = $this->withHeader('X-Company-Id', (string) $otherCompanyId)
        ->getJson('/api/v1/reports/dashboard?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json();

    expect($theirs['company']['id'])->toBe($otherCompanyId)
        ->and(nxDashCards($theirs)['revenue']['value'])->toBe('0.0000');
});

/*
|--------------------------------------------------------------------------
| A section that cannot be read leaves the rest of the dashboard standing
|--------------------------------------------------------------------------
*/

it('answers for every other section when one cannot be read', function () {
    // A closed year is a real state rather than a broken query: the books are
    // still there to report, but nothing can be projected out of a year the
    // company has declared finished. The forecast section has to say so on its
    // own rather than taking the page down with it.
    //
    // The entry is posted while the year is still open — the database refuses
    // to post into a closed one — and the year is closed afterwards, so the
    // ledger the other sections read is real and the projection is the only
    // section that cannot answer.
    $this->postJournal($this->balancedJournal('JV-000001', '300.0000'));

    DB::table('fiscal_years')
        ->where('id', $this->fiscalYearId)
        ->update(['status' => 'closed']);

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $payload = nxDashWindowFor();
    $sections = $payload['sections'];

    // The forecast is the section that cannot answer...
    expect($sections['forecast']['data'])->toBeNull()
        ->and($sections['forecast']['error']['message'])->toBeString()
        ->and($sections['forecast']['error']['type'])->not->toBe('');

    // ...and every other section still holds its figure.
    expect($sections['kpis']['error'])->toBeNull()
        ->and(nxDashCards($payload)['revenue']['value'])->toBe('300.0000')
        ->and($sections['ageing']['error'])->toBeNull()
        ->and($sections['reconciliation']['error'])->toBeNull()
        ->and($sections['alerts']['error'])->toBeNull()
        ->and($sections['approvals']['error'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The projection is labelled, and never mixed with what was posted
|--------------------------------------------------------------------------
*/

it('marks the projection as a forecast and keeps it out of the actuals', function () {
    $this->postJournal($this->balancedJournal('JV-000001', '300.0000'));

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $forecast = nxDashWindowFor()['sections']['forecast']['data'];

    // The section says what it is, in the payload rather than only on the
    // screen, so nothing downstream can present a projection as a fact.
    expect($forecast['forecast_is_not_actual'])->toBeTrue()
        ->and($forecast['note'])->toContain('projection and not a transaction')
        // Only periods that exist in the open year are projected.
        ->and($forecast['horizon']['period_count'])->toBeGreaterThan(0);

    // The window ends inside the current period, so the first block is that
    // period: it is projected, and it still states what was actually posted
    // beside what is projected. The 300 the profit and loss reports sits in
    // the actual column, in its own place, never folded into the forecast.
    expect($forecast['periods'][0]['is_forecast'])->toBeTrue()
        ->and($forecast['periods'][0]['revenue']['actual'])->toBe('300.0000')
        ->and($forecast['periods'][0]['revenue']['forecast'])->toBeString()
        // The horizon total keeps the two apart as well: the actual is the
        // posted ledger, and the projected figure is reported separately
        // rather than added to it.
        ->and($forecast['horizon_totals']['revenue']['actual'])->toBe('300.0000')
        ->and($forecast['horizon_totals']['revenue']['forecast'])->toBeString();
});

/*
|--------------------------------------------------------------------------
| The dashboard as a file
|--------------------------------------------------------------------------
*/

it('renders the dashboard as a CSV that states what the endpoint states', function () {
    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $report = nxDashWindowFor();

    $this->postJson('/api/v1/exports', [
        'type' => 'financial_manager_dashboard',
        'format' => 'csv',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertStatus(202);

    $ulid = (string) DB::table('exports')->value('ulid');
    $record = $this->getJson('/api/v1/exports/'.$ulid)->assertOk()->json('data');

    expect($record['status'])->toBe('done')
        ->and($record['file_name'])->toEndWith('.csv');

    $csv = $this->get($record['download_url'])->assertOk()->streamedContent();

    expect($csv)->toContain('Financial Manager Dashboard')
        // The same revenue figure the endpoint, and the card on the screen,
        // state.
        ->and($csv)->toContain((string) nxDashCards($report)['revenue']['value'])
        // And the projection block is labelled wherever it appears.
        ->and($csv)->toContain('FORECAST — NOT ACTUAL');
});

it('renders the dashboard as a PDF', function () {
    $this->postJournal($this->balancedJournal('JV-000001', '500.0000'));

    Sanctum::actingAs(nxDashMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->postJson('/api/v1/exports', [
        'type' => 'financial_manager_dashboard',
        'format' => 'pdf',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertStatus(202);

    $ulid = (string) DB::table('exports')->value('ulid');
    $record = $this->getJson('/api/v1/exports/'.$ulid)->assertOk()->json('data');

    expect($record['status'])->toBe('done')
        ->and($record['file_name'])->toEndWith('.pdf')
        ->and($record['size_bytes'])->toBeGreaterThan(0);

    $pdf = $this->get($record['download_url'])->assertOk()->streamedContent();

    // A real PDF rather than an empty file with a .pdf name.
    expect($pdf)->toStartWith('%PDF');
});

it('refuses a dashboard export to a member without the report right', function () {
    Sanctum::actingAs(nxDashMember($this->companyId));

    $this->postJson('/api/v1/exports', [
        'type' => 'financial_manager_dashboard',
        'format' => 'csv',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertForbidden();
});
