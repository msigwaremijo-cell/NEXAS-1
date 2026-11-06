<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The Reports section of the Financial Manager portal, and the boundary behind
// it. The section is one static window served as a file — it holds no state and
// carries no secrets — so what the server decides here is only which addresses
// are real. What a report may be read by is still decided by the API, which is
// why the last two tests in this file knock on the endpoint rather than on the
// page: a page that hides a button is not a permission.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding one company-scoped role — or, when no role is
 * named, none at all.
 */
function nxFmReportsMember(int $companyId, ?string $role = null): User
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
 * A second tenant, used to prove a report cannot be pointed at books the
 * caller has no right to read.
 */
function nxFmReportsForeignCompanyId(): int
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
 * The path the portal shell is served from, in one separator so it compares the
 * same on every platform.
 */
function nxFmReportsShellPath(): string
{
    return str_replace('\\', '/', public_path('fm-portal/index.html'));
}

/*
|--------------------------------------------------------------------------
| The address a report lives at
|--------------------------------------------------------------------------
*/

it('serves the portal shell at the reports hub', function () {
    $response = $this->get('/financial-manager/reports');

    $response->assertOk()->assertHeader('content-type', 'text/html; charset=utf-8');

    expect(str_replace('\\', '/', (string) $response->baseResponse->getFile()->getRealPath()))
        ->toBe(nxFmReportsShellPath());
});

it('serves the portal shell for every report the hub can open', function (string $report) {
    // A reload, a bookmark or a shared link to a report lands on that report
    // rather than back at the hub, which means the server has to answer with
    // the shell for the report's own address and not only for the hub's.
    $response = $this->get('/financial-manager/reports/'.$report);

    $response->assertOk()->assertHeader('content-type', 'text/html; charset=utf-8');

    expect(str_replace('\\', '/', (string) $response->baseResponse->getFile()->getRealPath()))
        ->toBe(nxFmReportsShellPath());
})->with([
    'profit-and-loss',
    'balance-sheet',
    'cash-flow',
    'cash-flow-indirect',
    'trial-balance',
    'general-ledger',
    'receivables-ageing',
    'payables-ageing',
    'stock-valuation',
    'budget-vs-actual',
    'cash-position',
]);

it('answers a report the portal does not carry with a 404', function (string $report) {
    // A name that is not one of the portal's reports is a 404 rather than a
    // second copy of the shell. `forecast` and `vat-return` are real API
    // reports that the hub deliberately does not open, and the bank
    // reconciliation summary is a card whose viewer arrives with its backing.
    $this->get('/financial-manager/reports/'.$report)->assertNotFound();
})->with([
    'not-a-report',
    'forecast',
    'vat-return',
    'bank-reconciliation',
]);

/*
|--------------------------------------------------------------------------
| The boundary behind the page
|--------------------------------------------------------------------------
*/

it('refuses a report to a member holding no role in the company', function () {
    // Every seeded role holds report.view, so the member who lacks it is one
    // who has been given no role at all. Hiding the card in the window would
    // not have stopped this request; the endpoint does.
    Sanctum::actingAs(nxFmReportsMember($this->companyId));

    $this->getJson('/api/v1/reports/profit-and-loss')->assertForbidden();
    $this->getJson('/api/v1/reports/trial-balance')->assertForbidden();
});

it('refuses a report for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxFmReportsMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->withHeader('X-Company-Id', (string) nxFmReportsForeignCompanyId())
        ->getJson('/api/v1/reports/profit-and-loss')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

it('lets the Financial Manager read the reports the hub opens', function () {
    Sanctum::actingAs(nxFmReportsMember($this->companyId, AccessMatrix::ROLE_FINANCIAL_MANAGER));

    $this->getJson('/api/v1/reports/profit-and-loss')->assertOk();
    $this->getJson('/api/v1/reports/trial-balance')->assertOk();
    $this->getJson('/api/v1/reports/cash-position')->assertOk();
});
