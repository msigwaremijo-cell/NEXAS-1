<?php

use App\Domain\Access\AccessMatrix;
use App\Domain\Assets\Actions\SaveAsset;
use App\Domain\Assets\Actions\SaveAssetCategory;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\FiscalPeriod;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// The register is what the company holds; the ledger is what it is worth. Every
// test here is about the two staying in step: a cost is a posting, a month's
// charge is a posting, a disposal is a posting, and the register totals are
// proved against the accounts the postings landed in. Nothing is asserted about
// the accounting method beyond what it says of itself — that it is
// straight-line and that its useful lives need an accountant's confirmation.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // 1010 is the bank the acquisition is met from; the four below are the
    // category's own accounts: an asset carried at cost, a contra-asset with a
    // credit balance, the expense a charge lands on, and where a gain or loss
    // on disposal is booked.
    $this->assetAccountId = $this->makeAccount('1500', 'Plant and Machinery', 'asset', 'debit');
    $this->accumulatedAccountId = $this->makeAccount('1590', 'Accumulated Depreciation', 'asset', 'credit');
    $this->depreciationExpenseAccountId = $this->makeAccount('5500', 'Depreciation Expense', 'expense', 'debit');
    $this->disposalAccountId = $this->makeAccount('5900', 'Gain or Loss on Disposal', 'expense', 'debit');
});

/*
|--------------------------------------------------------------------------
| Fixture helpers
|--------------------------------------------------------------------------
*/

/**
 * The publicly addressed ULID of a fiscal period.
 */
function nxAssetPeriodUlid(int $fiscalPeriodId): string
{
    return (string) DB::table('fiscal_periods')->where('id', $fiscalPeriodId)->value('ulid');
}

/**
 * A further month on the company's calendar, so a life can be run over several
 * periods.
 */
function nxAssetInstallPeriod(
    int $companyId,
    int $fiscalYearId,
    int $periodNo,
    string $name,
    string $startsOn,
    string $endsOn,
): int {
    return DB::table('fiscal_periods')->insertGetId([
        'ulid' => (string) Str::ulid(),
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
}

/**
 * A member of a company, holding exactly one company-scoped role. A null role
 * leaves them a member with no rights at all.
 */
function nxAssetMember(int $companyId, ?string $role = null): User
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
 * A category written through the action, so the tests that are not about the
 * category endpoint still start from a real one.
 *
 * @param  array<string, mixed>  $overrides
 */
function nxAssetCategory(
    int $companyId,
    int $assetAccountId,
    int $accumulatedAccountId,
    int $expenseAccountId,
    int $disposalAccountId,
    array $overrides = [],
): AssetCategory {
    return app(SaveAssetCategory::class)($companyId, array_merge([
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $assetAccountId,
        'accumulated_depreciation_account_id' => $accumulatedAccountId,
        'depreciation_expense_account_id' => $expenseAccountId,
        'disposal_account_id' => $disposalAccountId,
        'default_useful_life_months' => 12,
    ], $overrides));
}

/**
 * An asset written through the action. In service unless the caller says
 * otherwise, which posts its cost exactly as the endpoint would.
 *
 * @param  array<string, mixed>  $overrides
 */
function nxAsset(
    int $companyId,
    AssetCategory $category,
    int $acquisitionAccountId,
    array $overrides = [],
): Asset {
    return app(SaveAsset::class)($companyId, array_merge([
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'salvage_value' => '120.0000',
        'useful_life_months' => 12,
        'status' => 'in_service',
        'in_service_date' => '2026-03-15',
        'acquisition_account_id' => $acquisitionAccountId,
    ], $overrides));
}

function nxAssetPeriod(int $fiscalPeriodId): FiscalPeriod
{
    return FiscalPeriod::query()->findOrFail($fiscalPeriodId);
}

/**
 * What the ledger holds on one account, read from the lines a trigger has
 * already decided are in it. Reversed journals count, which is what makes this
 * the figure the trial balance would show.
 *
 * @return array{debit: string, credit: string}
 */
function nxAssetLedgerBalance(int $companyId, int $accountId): array
{
    $row = DB::table('journal_lines')
        ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
        ->where('journals.company_id', $companyId)
        ->where('journal_lines.account_id', $accountId)
        ->whereIn('journals.status', ['posted', 'reversed'])
        ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) AS debit, COALESCE(SUM(journal_lines.credit), 0) AS credit')
        ->first();

    return ['debit' => (string) $row->debit, 'credit' => (string) $row->credit];
}

/**
 * The lines of one journal, in the order they were written.
 *
 * @return list<array{account_id: int, debit: string, credit: string}>
 */
function nxAssetJournalLines(int $journalId): array
{
    return DB::table('journal_lines')
        ->where('journal_id', $journalId)
        ->orderBy('line_no')
        ->get()
        ->map(static fn ($line): array => [
            'account_id' => (int) $line->account_id,
            'debit' => (string) $line->debit,
            'credit' => (string) $line->credit,
        ])
        ->all();
}

/**
 * A second tenant, used to prove an account, a category and an asset cannot be
 * pointed at books the caller is not a member of.
 *
 * @return array{company_id: int, account_id: int, period_ulid: string}
 */
function nxAssetForeignCompany(): array
{
    $now = now();

    $companyId = DB::table('companies')->insertGetId([
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

    $fiscalYearId = DB::table('fiscal_years')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'name' => 'FY2026',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-12-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $periodUlid = (string) Str::ulid();

    DB::table('fiscal_periods')->insert([
        'ulid' => $periodUlid,
        'company_id' => $companyId,
        'fiscal_year_id' => $fiscalYearId,
        'period_no' => 3,
        'name' => 'March 2026',
        'starts_on' => '2026-03-01',
        'ends_on' => '2026-03-31',
        'status' => 'open',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $accountId = DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => '1500',
        'name' => 'Plant and Machinery',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['company_id' => $companyId, 'account_id' => $accountId, 'period_ulid' => $periodUlid];
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

it('writes a category with its four accounts and records it in the audit chain', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $before = DB::table('audit_logs')->count();

    $response = $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->assetAccountId,
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 60,
    ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'MACH')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.default_useful_life_months', 60)
        ->assertJsonPath('data.row_version', 0)
        // The accounts come back nested by the role they play, so a reader does
        // not have to know that the contra-asset is the second one.
        ->assertJsonPath('data.accounts.asset.code', '1500')
        ->assertJsonPath('data.accounts.accumulated_depreciation.code', '1590')
        ->assertJsonPath('data.accounts.depreciation_expense.code', '5500')
        ->assertJsonPath('data.accounts.disposal.code', '5900');

    expect($response->json('data.ulid'))->toBeString()->toHaveLength(26);

    $entry = DB::table('audit_logs')->where('action', 'asset_category.created')->sole();

    expect(DB::table('audit_logs')->count())->toBe($before + 1)
        ->and(strlen((string) $entry->hash))->toBe(64);
});

it('refuses a category whose asset account is not a debit-normal asset account', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // Sales revenue: the cost would be carried in a revenue account.
    $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->revenueAccountId,
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 60,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'AssetsException');

    expect(DB::table('asset_categories')->count())->toBe(0);
});

it('refuses a category whose accumulated depreciation account is not a contra-asset', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    // Cash on hand is an asset with a debit balance, so it is not the contra
    // side a charge is credited to.
    $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->assetAccountId,
        'accumulated_depreciation_account_id' => $this->cashAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 60,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'AssetsException');
});

it('refuses a category whose depreciation expense account is not an expense', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->assetAccountId,
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->assetAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 60,
    ])->assertStatus(422);
});

it('refuses a category whose disposal account is not an expense', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->assetAccountId,
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->assetAccountId,
        'default_useful_life_months' => 60,
    ])->assertStatus(422);
});

it('refuses a category naming an account of another company', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $foreign = nxAssetForeignCompany();

    $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $foreign['account_id'],
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 60,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('asset_account_id');

    expect(DB::table('asset_categories')->count())->toBe(0);
});

it('records nothing when a category is amended as it already stands', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $created = $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->assetAccountId,
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 60,
    ])->assertCreated()->json('data');

    $after = DB::table('audit_logs')->count();

    $this->patchJson('/api/v1/asset-categories/'.$created['ulid'], ['name' => 'Plant and Machinery'])
        ->assertOk()
        ->assertJsonPath('data.row_version', 0);

    expect(DB::table('audit_logs')->count())->toBe($after);
});

/*
|--------------------------------------------------------------------------
| Entering an asset
|--------------------------------------------------------------------------
*/

it('refuses an asset entered in service without naming the account its cost was met from', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'status' => 'in_service',
        'in_service_date' => '2026-03-15',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'AssetsException');

    // The whole entry is rolled back, not just the posting: a register row
    // saying in service with no cost in the books would be worse than nothing.
    expect(DB::table('assets')->count())->toBe(0)
        ->and(DB::table('journals')->count())->toBe(0);
});

it('creates a draft asset without touching the ledger', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $journals = DB::table('journals')->count();

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'salvage_value' => '120.0000',
        'useful_life_months' => 12,
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.net_book_value', '1200.0000')
        ->assertJsonPath('data.accumulated_depreciation', '0.0000')
        ->assertJsonPath('data.in_service_date', null);

    expect(DB::table('journals')->count())->toBe($journals);
});

it('posts the cost of an asset placed in service', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'salvage_value' => '120.0000',
        'useful_life_months' => 12,
        'status' => 'in_service',
        'in_service_date' => '2026-03-15',
        'acquisition_account_id' => $this->bankLedgerAccountId,
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'in_service')
        ->assertJsonPath('data.in_service_date', '2026-03-15');

    $journal = DB::table('journals')->where('journal_type', 'acquisition')->sole();

    expect($journal->status)->toBe('posted');

    // Dr the category's asset account, Cr the account the cost was met from.
    expect(nxAssetJournalLines((int) $journal->id))->toBe([
        ['account_id' => $this->assetAccountId, 'debit' => '1200.0000', 'credit' => '0.0000'],
        ['account_id' => $this->bankLedgerAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
    ]);
});

it('refuses a draft asset that carries an in-service date', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'status' => 'draft',
        'in_service_date' => '2026-03-15',
    ])->assertStatus(422);
});

it('refuses an asset placed in service with no date to count from', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'status' => 'in_service',
        'acquisition_account_id' => $this->bankLedgerAccountId,
    ])->assertStatus(422);
});

it('refuses an asset that costs nothing or carries an impossible salvage value', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $body = [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'useful_life_months' => 12,
    ];

    $this->postJson('/api/v1/assets', [...$body, 'acquisition_cost' => '0.0000'])->assertStatus(422);
    $this->postJson('/api/v1/assets', [...$body, 'acquisition_cost' => '-5.0000'])->assertStatus(422);
    $this->postJson('/api/v1/assets', [
        ...$body,
        'acquisition_cost' => '100.0000',
        'salvage_value' => '200.0000',
    ])->assertStatus(422);
    $this->postJson('/api/v1/assets', [
        ...$body,
        'acquisition_cost' => '100.0000',
        'salvage_value' => '-1.0000',
    ])->assertStatus(422);

    expect(DB::table('assets')->count())->toBe(0);
});

it('refuses an asset entered against a category that has been retired', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
        ['status' => 'inactive'],
    );

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
    ])->assertStatus(422);
});

it('refuses an asset entered against a category of another company', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $foreign = nxAssetForeignCompany();

    $foreignCategory = DB::table('asset_categories')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $foreign['company_id'],
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $foreign['account_id'],
        'accumulated_depreciation_account_id' => $foreign['account_id'],
        'depreciation_expense_account_id' => $foreign['account_id'],
        'disposal_account_id' => $foreign['account_id'],
        'default_useful_life_months' => 12,
        'status' => 'active',
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $foreignCategory,
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('asset_category_id');

    expect(DB::table('assets')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

it('refuses to dispose of an asset by moving its status', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category->getKey(),
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'status' => 'disposed',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');
});

it('refuses a status that moves backwards', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->patchJson('/api/v1/assets/'.$asset->ulid, ['status' => 'draft'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');

    expect(Asset::query()->findOrFail($asset->getKey())->status->value)->toBe('in_service');
});

it('refuses to restate the cost of an asset once it is in the books', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->patchJson('/api/v1/assets/'.$asset->ulid, ['acquisition_cost' => '1300.0000'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'AssetsException');

    expect((string) Asset::query()->findOrFail($asset->getKey())->acquisition_cost)->toBe('1200.0000');
});

it('refuses to move an asset to another category once its cost is posted', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $other = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
        ['code' => 'FURN', 'name' => 'Furniture and Fittings'],
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->patchJson('/api/v1/assets/'.$asset->ulid, ['asset_category_id' => $other->getKey()])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');
});

it('lets a draft asset be amended freely', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $draft = nxAsset($this->companyId, $category, $this->bankLedgerAccountId, ['status' => 'draft', 'in_service_date' => null]);

    $this->patchJson('/api/v1/assets/'.$draft->ulid, [
        'name' => 'Bench lathe',
        'acquisition_cost' => '1500.0000',
        'useful_life_months' => 24,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bench lathe')
        ->assertJsonPath('data.acquisition_cost', '1500.0000')
        ->assertJsonPath('data.useful_life_months', 24);
});

/*
|--------------------------------------------------------------------------
| The straight-line month
|--------------------------------------------------------------------------
*/

it('charges straight-line month by month from the in-service date', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    // (1200 - 120) / 12 = 90 a month.
    $response = $this->postJson('/api/v1/depreciation-runs', [
        'fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId),
    ])
        ->assertCreated()
        ->assertJsonPath('data.journal_type', 'depreciation')
        ->assertJsonPath('data.status', 'posted');

    $journalId = (int) $response->json('data.id');

    expect(nxAssetJournalLines($journalId))->toBe([
        ['account_id' => $this->depreciationExpenseAccountId, 'debit' => '90.0000', 'credit' => '0.0000'],
        ['account_id' => $this->accumulatedAccountId, 'debit' => '0.0000', 'credit' => '90.0000'],
    ]);

    $this->getJson('/api/v1/assets')->assertOk();
});

it('takes the remainder in the period the life runs out, so the life lands exactly', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
        ['default_useful_life_months' => 3],
    );

    // 100 over three months does not divide evenly: 33.3333 a month leaves a
    // fraction behind, and the last period takes it.
    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId, [
        'code' => 'MACH-001',
        'acquisition_cost' => '100.0000',
        'salvage_value' => '0.0000',
        'useful_life_months' => 3,
        'in_service_date' => '2026-03-01',
    ]);

    $april = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30');
    $may = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 5, 'May 2026', '2026-05-01', '2026-05-31');

    foreach ([$this->fiscalPeriodId, $april, $may] as $periodId) {
        $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($periodId)])
            ->assertCreated();
    }

    $fresh = Asset::query()->findOrFail($asset->getKey());

    // Cost less salvage, to the shilling: 33.3333 + 33.3333 + 33.3334.
    expect((string) $fresh->accumulated_depreciation)->toBe('100.0000')
        ->and($fresh->netBookValue()->toDecimal())->toBe('0.0000')
        ->and($fresh->depreciableRemaining()->toDecimal())->toBe('0.0000');
});

it('never charges an asset below its salvage value', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
        ['default_useful_life_months' => 3],
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId, [
        'code' => 'MACH-001',
        'acquisition_cost' => '100.0000',
        'salvage_value' => '20.0000',
        'useful_life_months' => 3,
        'in_service_date' => '2026-03-01',
    ]);

    $april = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30');
    $may = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 5, 'May 2026', '2026-05-01', '2026-05-31');

    foreach ([$this->fiscalPeriodId, $april, $may] as $periodId) {
        $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($periodId)])
            ->assertCreated();
    }

    $fresh = Asset::query()->findOrFail($asset->getKey());

    expect((string) $fresh->accumulated_depreciation)->toBe('80.0000')
        ->and($fresh->netBookValue()->toDecimal())->toBe('20.0000');

    // A month past the end of the life has nothing left to give, and the run
    // says so rather than posting a journal of nothing.
    $june = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 6, 'June 2026', '2026-06-01', '2026-06-30');

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($june)])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');

    expect((string) Asset::query()->findOrFail($asset->getKey())->accumulated_depreciation)->toBe('80.0000');
});

/*
|--------------------------------------------------------------------------
| Posting a run
|--------------------------------------------------------------------------
*/

it('writes one journal for the period with a pair of lines for each asset', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId, ['code' => 'MACH-001']);
    nxAsset($this->companyId, $category, $this->bankLedgerAccountId, ['code' => 'MACH-002', 'name' => 'Press']);

    $response = $this->postJson('/api/v1/depreciation-runs', [
        'fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId),
    ])->assertCreated();

    $journalId = (int) $response->json('data.id');

    // Four lines: one debit and one credit per asset, and the entry balances.
    expect(nxAssetJournalLines($journalId))->toHaveCount(4)
        ->and($response->json('data.total_debit'))->toBe('180.0000')
        ->and($response->json('data.total_credit'))->toBe('180.0000')
        ->and($response->json('data.asset_charges'))->toHaveCount(2);

    // The period's own record of what it charged each asset.
    expect(DB::table('asset_depreciation_lines')->count())->toBe(2);
});

it('refuses a second run for a period that has already been depreciated', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $period = nxAssetPeriodUlid($this->fiscalPeriodId);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => $period])->assertCreated();

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => $period])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');

    expect(DB::table('journals')->where('journal_type', 'depreciation')->count())->toBe(1)
        ->and(DB::table('asset_depreciation_lines')->count())->toBe(1);
});

it('refuses a run against a period the calendar has closed', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->update(['status' => 'closed']);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertStatus(409)
        ->assertJsonPath('error', 'FiscalPeriodNotOpenException');

    expect(DB::table('journals')->count())->toBe(1);
});

it('refuses a run against a year the calendar has closed', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    DB::table('fiscal_years')->where('id', $this->fiscalYearId)->update(['status' => 'closed']);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertStatus(409)
        ->assertJsonPath('error', 'FiscalPeriodNotOpenException');
});

it('refuses a run against a period of another company', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $foreign = nxAssetForeignCompany();

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => $foreign['period_ulid']])
        ->assertNotFound();
});

it('leaves a draft asset out of a run', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId, ['status' => 'draft', 'in_service_date' => null]);

    $journals = DB::table('journals')->count();

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');

    expect(DB::table('journals')->count())->toBe($journals);
});

it('undoes a run by reversing its journal and giving the assets back', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $period = nxAssetPeriodUlid($this->fiscalPeriodId);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => $period])->assertCreated();

    $this->postJson('/api/v1/depreciation-runs/reversal', ['fiscal_period' => $period])
        ->assertCreated()
        ->assertJsonPath('data.journal_type', 'reversal');

    $original = DB::table('journals')->where('journal_type', 'depreciation')->sole();

    expect($original->status)->toBe('reversed')
        ->and((string) Asset::query()->findOrFail($asset->getKey())->accumulated_depreciation)->toBe('0.0000')
        // The run's own per-asset record is given back with it, which is what
        // reopens the period.
        ->and(DB::table('asset_depreciation_lines')->count())->toBe(0);

    // The period can be run again now that the journal has been put back.
    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => $period])->assertCreated();

    expect((string) Asset::query()->findOrFail($asset->getKey())->accumulated_depreciation)->toBe('90.0000');
});

it('refuses to reverse a period that was never run', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/depreciation-runs/reversal', [
        'fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId),
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');
});

/*
|--------------------------------------------------------------------------
| Disposal
|--------------------------------------------------------------------------
*/

it('books a gain on disposal and takes the asset out of the books', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertCreated();

    // Charged 90, so it is carried at 1200 - 90 = 1110 and sold for 1200: 90 of
    // gain.
    $response = $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-31',
        'proceeds' => '1200.0000',
        'proceeds_account_id' => $this->bankLedgerAccountId,
    ])
        ->assertCreated()
        ->assertJsonPath('data.journal_type', 'disposal');

    $journalId = (int) $response->json('data.id');

    expect(nxAssetJournalLines($journalId))->toBe([
        ['account_id' => $this->accumulatedAccountId, 'debit' => '90.0000', 'credit' => '0.0000'],
        ['account_id' => $this->assetAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
        ['account_id' => $this->bankLedgerAccountId, 'debit' => '1200.0000', 'credit' => '0.0000'],
        ['account_id' => $this->disposalAccountId, 'debit' => '0.0000', 'credit' => '90.0000'],
    ]);

    $fresh = Asset::query()->findOrFail($asset->getKey());

    expect($fresh->status->value)->toBe('disposed')
        ->and($fresh->disposed_on?->toDateString())->toBe('2026-03-31')
        ->and((string) $fresh->disposal_proceeds)->toBe('1200.0000')
        ->and((int) $fresh->disposal_journal_id)->toBe($journalId)
        // What it cost and what it had been depreciated by stay on the row:
        // the register's answer to a disposed asset is history.
        ->and((string) $fresh->acquisition_cost)->toBe('1200.0000')
        ->and((string) $fresh->accumulated_depreciation)->toBe('90.0000');

    expect(DB::table('audit_logs')->where('action', 'asset.disposed')->count())->toBe(1);
});

it('books a loss on disposal', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertCreated();

    // Carried at 1110 and sold for 500: 610 of loss, a debit to the disposal
    // account.
    $response = $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-31',
        'proceeds' => '500.0000',
        'proceeds_account_id' => $this->bankLedgerAccountId,
    ])->assertCreated();

    expect(nxAssetJournalLines((int) $response->json('data.id')))->toBe([
        ['account_id' => $this->accumulatedAccountId, 'debit' => '90.0000', 'credit' => '0.0000'],
        ['account_id' => $this->assetAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
        ['account_id' => $this->bankLedgerAccountId, 'debit' => '500.0000', 'credit' => '0.0000'],
        ['account_id' => $this->disposalAccountId, 'debit' => '610.0000', 'credit' => '0.0000'],
    ]);
});

it('books the whole net book value as a loss when an asset is scrapped for nothing', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertCreated();

    // Nothing received, so no proceeds account is named: there is no second
    // side to give it.
    $response = $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-31',
    ])
        ->assertCreated()
        ->assertJsonPath('data.total_debit', '1200.0000')
        ->assertJsonPath('data.total_credit', '1200.0000');

    expect(nxAssetJournalLines((int) $response->json('data.id')))->toBe([
        ['account_id' => $this->accumulatedAccountId, 'debit' => '90.0000', 'credit' => '0.0000'],
        ['account_id' => $this->assetAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
        ['account_id' => $this->disposalAccountId, 'debit' => '1110.0000', 'credit' => '0.0000'],
    ]);
});

it('brings depreciation current for the period the disposal falls in, then stops', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    // No run has been made for March, and the asset leaves in March: its own
    // month is charged first, so the figures taken out are the right ones.
    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    // 1200 - 90 = 1110 carried, sold for exactly that: nothing to book.
    $response = $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-20',
        'proceeds' => '1110.0000',
        'proceeds_account_id' => $this->bankLedgerAccountId,
    ])->assertCreated();

    expect(DB::table('journals')->where('journal_type', 'depreciation')->count())->toBe(1)
        ->and(nxAssetJournalLines((int) $response->json('data.id')))->toBe([
            ['account_id' => $this->accumulatedAccountId, 'debit' => '90.0000', 'credit' => '0.0000'],
            ['account_id' => $this->assetAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
            ['account_id' => $this->bankLedgerAccountId, 'debit' => '1110.0000', 'credit' => '0.0000'],
        ]);

    // And a later run finds it gone: it is no longer in service.
    $april = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30');

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($april)])
        ->assertStatus(409);
});

it('refuses a disposal of an asset that is not in service', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $draft = nxAsset($this->companyId, $category, $this->bankLedgerAccountId, ['status' => 'draft', 'in_service_date' => null]);

    $this->postJson('/api/v1/assets/'.$draft->ulid.'/disposal', ['disposal_date' => '2026-03-31'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');

    expect(DB::table('journals')->count())->toBe(0);
});

it('refuses a disposal dated before the asset went into service', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId, ['in_service_date' => '2026-03-15']);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', ['disposal_date' => '2026-03-01'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'AssetsException');
});

it('refuses negative proceeds and proceeds with no account to receive them', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-31',
        'proceeds' => '-500.0000',
    ])->assertStatus(422);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-31',
        'proceeds' => '500.0000',
    ])->assertStatus(422);

    expect(Asset::query()->findOrFail($asset->getKey())->status->value)->toBe('in_service');
});

it('refuses a disposal into a period the calendar has closed', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    DB::table('fiscal_periods')->where('id', $this->fiscalPeriodId)->update(['status' => 'closed']);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', ['disposal_date' => '2026-03-31'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'FiscalPeriodNotOpenException');
});

it('refuses to dispose of an asset a second time', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', ['disposal_date' => '2026-03-31'])
        ->assertCreated();

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', ['disposal_date' => '2026-03-31'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');

    expect(DB::table('journals')->where('journal_type', 'disposal')->count())->toBe(1);
});

it('refuses an amendment to a disposed asset', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', ['disposal_date' => '2026-03-31'])
        ->assertCreated();

    $this->patchJson('/api/v1/assets/'.$asset->ulid, ['name' => 'Something else'])
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');
});

it('brings a disposed asset back by reversing its disposal', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', [
        'disposal_date' => '2026-03-31',
        'proceeds' => '1200.0000',
        'proceeds_account_id' => $this->bankLedgerAccountId,
    ])->assertCreated();

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal/reversal')
        ->assertCreated()
        ->assertJsonPath('data.journal_type', 'reversal');

    expect(DB::table('journals')->where('journal_type', 'disposal')->sole()->status)->toBe('reversed');

    $fresh = Asset::query()->findOrFail($asset->getKey());

    expect($fresh->status->value)->toBe('in_service')
        ->and($fresh->disposed_on)->toBeNull()
        ->and($fresh->disposal_proceeds)
        // A disposal never changed the cost or the accumulated depreciation, so
        // bringing the asset back changes neither.
        ->and((string) $fresh->acquisition_cost)->toBe('1200.0000')
        ->and((string) $fresh->accumulated_depreciation)->toBe('90.0000');
});

it('refuses to reverse a disposal that was never made', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal/reversal')
        ->assertStatus(409)
        ->assertJsonPath('error', 'AssetStateException');
});

/*
|--------------------------------------------------------------------------
| The register
|--------------------------------------------------------------------------
*/

it('reports the register totals by category and says what its method is', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertCreated();

    $this->getJson('/api/v1/assets/register')
        ->assertOk()
        ->assertJsonPath('data.asset_count', 1)
        ->assertJsonPath('data.cost', '1200.0000')
        ->assertJsonPath('data.accumulated_depreciation', '90.0000')
        ->assertJsonPath('data.net_book_value', '1110.0000')
        ->assertJsonCount(1, 'data.by_category')
        ->assertJsonPath('data.by_category.0.asset_count', 1)
        ->assertJsonPath('data.by_category.0.cost', '1200.0000')
        ->assertJsonPath('data.by_category.0.accumulated_depreciation', '90.0000');

    // The method is stated out loud rather than left to be assumed, and the
    // note says it is not a claim about tax depreciation.
    $this->getJson('/api/v1/assets')
        ->assertOk()
        ->assertJsonPath('depreciation.method', 'straight_line');

    expect((string) $this->getJson('/api/v1/assets')->json('depreciation.note'))
        ->toContain('accountant');
});

it('leaves a disposed asset out of the register totals', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $this->postJson('/api/v1/assets/'.$asset->ulid.'/disposal', ['disposal_date' => '2026-03-31'])
        ->assertCreated();

    $this->getJson('/api/v1/assets/register')
        ->assertOk()
        ->assertJsonPath('data.asset_count', 0)
        ->assertJsonPath('data.cost', '0.0000')
        ->assertJsonPath('data.net_book_value', '0.0000');

    // The register still holds it: it is the register's report that answers
    // what is in service, and the asset list that still shows history.
    $this->getJson('/api/v1/assets')->assertOk()->assertJsonCount(1, 'data');
});

it('writes nothing when the register is read', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $journals = DB::table('journals')->count();
    $audits = DB::table('audit_logs')->count();

    $this->getJson('/api/v1/assets')->assertOk();
    $this->getJson('/api/v1/assets/register')->assertOk();
    $this->getJson('/api/v1/asset-categories')->assertOk();

    expect(DB::table('journals')->count())->toBe($journals)
        ->and(DB::table('audit_logs')->count())->toBe($audits);
});

/*
|--------------------------------------------------------------------------
| The sample life, and the tie-out
|--------------------------------------------------------------------------
*/

it('shows a sample asset through its life and ties the register out to the ledger', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $category = $this->postJson('/api/v1/asset-categories', [
        'code' => 'MACH',
        'name' => 'Plant and Machinery',
        'asset_account_id' => $this->assetAccountId,
        'accumulated_depreciation_account_id' => $this->accumulatedAccountId,
        'depreciation_expense_account_id' => $this->depreciationExpenseAccountId,
        'disposal_account_id' => $this->disposalAccountId,
        'default_useful_life_months' => 12,
    ])->assertCreated()->json('data');

    $april = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 4, 'April 2026', '2026-04-01', '2026-04-30');
    $may = nxAssetInstallPeriod($this->companyId, $this->fiscalYearId, 5, 'May 2026', '2026-05-01', '2026-05-31');

    // Acquisition: cost 1200, salvage 120, twelve months, entered on the 15th
    // of March for 1200 met from the bank.
    $asset = $this->postJson('/api/v1/assets', [
        'asset_category_id' => $category['id'],
        'code' => 'MACH-001',
        'name' => 'Lathe',
        'acquisition_cost' => '1200.0000',
        'salvage_value' => '120.0000',
        'useful_life_months' => 12,
        'status' => 'in_service',
        'in_service_date' => '2026-03-15',
        'acquisition_account_id' => $this->bankLedgerAccountId,
    ])->assertCreated()->json('data');

    $acquisition = DB::table('journals')->where('journal_type', 'acquisition')->sole();

    expect(nxAssetJournalLines((int) $acquisition->id))->toBe([
        ['account_id' => $this->assetAccountId, 'debit' => '1200.0000', 'credit' => '0.0000'],
        ['account_id' => $this->bankLedgerAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
    ]);

    // Three monthly runs: March, April and May, 90 each.
    foreach ([$this->fiscalPeriodId, $april, $may] as $periodId) {
        $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($periodId)])
            ->assertCreated()
            ->assertJsonPath('data.total_debit', '90.0000');
    }

    $runs = DB::table('journals')->where('journal_type', 'depreciation')->orderBy('id')->get();

    expect($runs)->toHaveCount(3)
        ->and((string) Asset::query()->findOrFail($asset['id'])->accumulated_depreciation)->toBe('270.0000');

    // The register at this point: 1200 carried, 270 charged, 930 net.
    $register = $this->getJson('/api/v1/assets/register')->assertOk()->json('data');

    expect($register['cost'])->toBe('1200.0000')
        ->and($register['accumulated_depreciation'])->toBe('270.0000')
        ->and($register['net_book_value'])->toBe('930.0000');

    // And it is the ledger's own figures, read from the accounts the postings
    // landed in rather than from the register a second time.
    $costLedger = nxAssetLedgerBalance($this->companyId, $this->assetAccountId);
    $accumulatedLedger = nxAssetLedgerBalance($this->companyId, $this->accumulatedAccountId);

    expect($register['cost'])->toBe(bcsub($costLedger['debit'], $costLedger['credit'], 4))
        ->and($register['accumulated_depreciation'])->toBe(bcsub($accumulatedLedger['credit'], $accumulatedLedger['debit'], 4));

    // Disposal on the last day of May for 1000: carried at 930, so 70 of gain.
    $disposal = $this->postJson('/api/v1/assets/'.$asset['ulid'].'/disposal', [
        'disposal_date' => '2026-05-31',
        'proceeds' => '1000.0000',
        'proceeds_account_id' => $this->bankLedgerAccountId,
    ])->assertCreated()->json('data');

    expect(nxAssetJournalLines((int) $disposal['id']))->toBe([
        ['account_id' => $this->accumulatedAccountId, 'debit' => '270.0000', 'credit' => '0.0000'],
        ['account_id' => $this->assetAccountId, 'debit' => '0.0000', 'credit' => '1200.0000'],
        ['account_id' => $this->bankLedgerAccountId, 'debit' => '1000.0000', 'credit' => '0.0000'],
        ['account_id' => $this->disposalAccountId, 'debit' => '0.0000', 'credit' => '70.0000'],
    ])
        ->and($disposal['total_debit'])->toBe('1270.0000')
        ->and($disposal['total_credit'])->toBe('1270.0000');

    // Out of the books on both sides: the register reports nothing in service,
    // and the two accounts it was carried in are back to nothing as well.
    $closing = $this->getJson('/api/v1/assets/register')->assertOk()->json('data');

    $costLedger = nxAssetLedgerBalance($this->companyId, $this->assetAccountId);
    $accumulatedLedger = nxAssetLedgerBalance($this->companyId, $this->accumulatedAccountId);

    expect($closing['cost'])->toBe('0.0000')
        ->and($closing['accumulated_depreciation'])->toBe('0.0000')
        ->and(bcsub($costLedger['debit'], $costLedger['credit'], 4))->toBe('0.0000')
        ->and(bcsub($accumulatedLedger['credit'], $accumulatedLedger['debit'], 4))->toBe('0.0000');
});

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses every asset route without a token', function () {
    $this->getJson('/api/v1/assets')->assertUnauthorized();
    $this->getJson('/api/v1/assets/register')->assertUnauthorized();
    $this->getJson('/api/v1/asset-categories')->assertUnauthorized();
    $this->postJson('/api/v1/assets', [])->assertUnauthorized();
    $this->postJson('/api/v1/asset-categories', [])->assertUnauthorized();
    $this->postJson('/api/v1/depreciation-runs', [])->assertUnauthorized();
});

it('refuses the register and the write for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $foreign = nxAssetForeignCompany();

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->getJson('/api/v1/assets/register')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});

it('answers not found for another company\u2019s asset', function () {
    $user = nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT);

    Sanctum::actingAs($user);

    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    $asset = nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    $foreign = nxAssetForeignCompany();

    // A member of both companies, working in the other one's books: the asset
    // is a 404 rather than an existence oracle.
    $user->companies()->attach($foreign['company_id'], ['is_default' => false, 'status' => 'active']);

    $this->withHeader('X-Company-Id', (string) $foreign['company_id'])
        ->getJson('/api/v1/assets/'.$asset->ulid)
        ->assertNotFound();
});

it('holds the register behind its own rights rather than the report rights', function () {
    $category = nxAssetCategory(
        $this->companyId,
        $this->assetAccountId,
        $this->accumulatedAccountId,
        $this->depreciationExpenseAccountId,
        $this->disposalAccountId,
    );

    nxAsset($this->companyId, $category, $this->bankLedgerAccountId);

    // The Read-only role reads the register and writes nothing of it.
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_READ_ONLY));

    $this->getJson('/api/v1/assets')->assertOk();
    $this->getJson('/api/v1/assets/register')->assertOk();
    $this->getJson('/api/v1/asset-categories')->assertOk();
    $this->postJson('/api/v1/assets', [])->assertForbidden();
    $this->postJson('/api/v1/asset-categories', [])->assertForbidden();
    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertForbidden();

    // The Auditor reads the register and charges nothing against it.
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/assets/register')->assertOk();
    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertForbidden();

    // The Cashier may see the catalogue of what the company holds and no more.
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/assets')->assertOk();
    $this->postJson('/api/v1/assets', [])->assertForbidden();

    // The Accountant keeps the register and charges the depreciation.
    Sanctum::actingAs(nxAssetMember($this->companyId, AccessMatrix::ROLE_ACCOUNTANT));

    $this->postJson('/api/v1/depreciation-runs', ['fiscal_period' => nxAssetPeriodUlid($this->fiscalPeriodId)])
        ->assertCreated();
});

it('refuses every asset route to a member with no role in the company', function () {
    Sanctum::actingAs(nxAssetMember($this->companyId));

    $this->getJson('/api/v1/assets')->assertForbidden();
    $this->getJson('/api/v1/assets/register')->assertForbidden();
    $this->getJson('/api/v1/asset-categories')->assertForbidden();
    $this->postJson('/api/v1/assets', [])->assertForbidden();
    $this->postJson('/api/v1/depreciation-runs', [])->assertForbidden();
});
