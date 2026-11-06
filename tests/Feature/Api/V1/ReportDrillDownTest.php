<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

/*
|--------------------------------------------------------------------------
| Drilling from a statement row into that account's ledger
|--------------------------------------------------------------------------
|
| A trial balance or profit-and-loss row is a doorway. Clicking an account
| opens the general ledger for the same account and the same window the figure
| was read over. The portal does the walking, but the promise it makes — that
| the ledger which opens carries the same window and reconciles to the figure
| that was clicked — is a property of these two endpoints read together, and
| that is what is pinned here.
|
| The window matters. A general ledger is not the trial balance seen again: it
| opens with whatever the account already stood at before the window began and
| closes with that plus the window's movement, while a trial balance row over
| the same window is the movement alone. A drill that forgot the window would
| quietly show a different span, or a closing figure with no opening to explain
| it. So the fixtures put a journal before the window on purpose.
*/

uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    $this->seed(RoleAndPermissionSeeder::class);

    // The books the drill tests read:
    //   JV-000001  05 March   cash +3,000, revenue +3,000   (before the window)
    //   JV-000002  15 March   cash +500,   revenue +500     (inside the window)
    //   JV-000003  15 March   expense +200, cash -200       (inside the window)
    //
    // Over 10–31 March the cash account therefore opens at 3,000, moves by
    // +300 within the window and closes at 3,300 — while the trial balance row
    // over the same window is the 300 of movement, not the 3,300 standing
    // balance. A drill has to reconcile the row to the movement without losing
    // the opening that makes the closing figure make sense.
    $expense = $this->makeAccount('6000', 'Rent Expense', 'expense', 'debit');

    $opening = $this->makeJournal('JV-000001');
    DB::table('journals')->where('id', $opening)->update(['posting_date' => '2026-03-05']);
    $this->addLine($opening, 1, $this->cashAccountId, '3000.0000', '0', '2026-03-05');
    $this->addLine($opening, 2, $this->revenueAccountId, '0', '3000.0000', '2026-03-05');
    $this->postJournal($opening);

    $this->postJournal($this->balancedJournal('JV-000002', '500.0000'));

    $payment = $this->makeJournal('JV-000003');
    $this->addLine($payment, 1, $expense, '200.0000', '0');
    $this->addLine($payment, 2, $this->cashAccountId, '0', '200.0000');
    $this->postJournal($payment);
});

/**
 * A member of a company holding one company-scoped role.
 */
function nxDrillMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, used to prove the ledger cannot be pointed at books the
 * caller is not a member of.
 */
function nxDrillForeignCompanyId(): int
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

/*
|--------------------------------------------------------------------------
| The trial balance row reconciles to the ledger it opens
|--------------------------------------------------------------------------
*/

it('reconciles a trial balance row to the ledger of that account over the same window', function () {
    Sanctum::actingAs(nxDrillMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $window = 'from=2026-03-10&to=2026-03-31';

    $trial = $this->getJson('/api/v1/reports/trial-balance?'.$window)->assertOk()->json();
    $row = collect($trial['data'])->firstWhere('account_id', $this->cashAccountId);

    expect($row)->not->toBeNull()
        ->and($row['net'])->toBe('300.0000');

    // The drill: same account, same window the row was read over.
    $ledger = $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId.'&'.$window)
        ->assertOk()
        ->json();

    $meta = $ledger['meta'];

    // The ledger that opens is the account that was clicked, over the window
    // that travelled with the click rather than a default one.
    expect($meta['account_id'])->toBe($this->cashAccountId)
        ->and($meta['from'])->toBe('2026-03-10')
        ->and($meta['to'])->toBe('2026-03-31');

    // A trial balance row is the window's movement, so it is the ledger's debit
    // less credit over the same window — exactly, to the money scale, not a
    // rounded near miss.
    expect(bcsub($meta['period_debit'], $meta['period_credit'], 4))->toBe($row['net']);

    // The ledger also carries what the account stood at before the window: the
    // figure the statement row cannot show and the drill would be wrong to lose.
    expect($meta['opening_balance'])->toBe('3000.0000')
        ->and($meta['closing_balance'])->toBe('3300.0000')
        ->and(bcadd($meta['opening_balance'], $row['net'], 4))->toBe($meta['closing_balance']);
});

it('opens on the window it was given rather than on a default one', function () {
    Sanctum::actingAs(nxDrillMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    // A window ending before any journal: the account opens and closes at zero.
    $early = $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId.'&from=2026-03-01&to=2026-03-04')
        ->assertOk()
        ->json('meta');

    expect($early['to'])->toBe('2026-03-04')
        ->and($early['opening_balance'])->toBe('0.0000')
        ->and($early['closing_balance'])->toBe('0.0000');

    // The full window, which must include everything and therefore differ.
    $full = $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId.'&from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->json('meta');

    expect($full['to'])->toBe('2026-03-31')
        ->and($full['closing_balance'])->toBe('3300.0000')
        ->and($full['closing_balance'])->not->toBe($early['closing_balance']);
});

/*
|--------------------------------------------------------------------------
| The profit and loss row reconciles to the ledger it opens
|--------------------------------------------------------------------------
*/

it('reconciles a profit and loss row to the ledger of that account over the same window', function () {
    Sanctum::actingAs(nxDrillMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $expenseId = (int) DB::table('accounts')->where('code', '6000')->value('id');

    $window = 'from=2026-03-10&to=2026-03-31';

    $statement = $this->getJson('/api/v1/reports/profit-and-loss?'.$window)->assertOk()->json();

    $revenue = collect($statement['revenue']['accounts'])->firstWhere('account_id', $this->revenueAccountId);
    $expense = collect($statement['expense']['accounts'])->firstWhere('account_id', $expenseId);

    expect($revenue)->not->toBeNull()->and($revenue['amount'])->toBe('500.0000');
    expect($expense)->not->toBeNull()->and($expense['amount'])->toBe('200.0000');

    // Revenue reads as its credit side, so the ledger's credit movement over
    // the window is the figure the row showed.
    $revenueLedger = $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->revenueAccountId.'&'.$window)
        ->assertOk()
        ->json('meta');

    expect($revenueLedger['account_id'])->toBe($this->revenueAccountId)
        ->and($revenueLedger['period_credit'])->toBe($revenue['amount']);

    // Expense reads as its debit side, so the ledger's debit movement is it.
    $expenseLedger = $this->getJson('/api/v1/reports/general-ledger?account_id='.$expenseId.'&'.$window)
        ->assertOk()
        ->json('meta');

    expect($expenseLedger['account_id'])->toBe($expenseId)
        ->and($expenseLedger['period_debit'])->toBe($expense['amount']);
});

/*
|--------------------------------------------------------------------------
| Whose books
|--------------------------------------------------------------------------
*/

it('refuses the drilled ledger to a member with no role in the company', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId.'&from=2026-03-10&to=2026-03-31')
        ->assertForbidden();
});

it('refuses the drilled ledger for a company the caller does not belong to', function () {
    Sanctum::actingAs(nxDrillMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->withHeader('X-Company-Id', (string) nxDrillForeignCompanyId())
        ->getJson('/api/v1/reports/general-ledger?account_id='.$this->cashAccountId.'&from=2026-03-10&to=2026-03-31')
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not have access to this company.');
});
