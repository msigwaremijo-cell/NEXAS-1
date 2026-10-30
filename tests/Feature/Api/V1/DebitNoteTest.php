<?php

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\GrniAllocation;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A supplier debit note is the purchase side correcting a bill it already
// posted. It prices itself from the bill line it answers, debits the payable,
// and — where the bill was for stock — sends the goods back out at the price
// the original receipt brought them in at. None of that can be proven around
// the schema, so this surface is exercised over the real database triggers and
// constraints, and the ledger is read back through the reporting engine rather
// than from the tables the test just wrote.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // A stock return needs the accounts an item's stock posts through, and a
    // purchase needs the two accounts a delivery is met by until it is billed.
    $this->inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->cogsAccountId = $this->makeAccount('5100', 'Cost of Sales', 'expense', 'debit');
    $this->grniAccountId = $this->makeAccount('2100', 'Goods Received Not Invoiced', 'liability', 'credit');
    $this->varianceAccountId = $this->makeAccount('5200', 'Purchase Price Variance', 'expense', 'debit');
    $this->capitalAccountId = $this->makeAccount('3000', 'Owner Capital', 'equity', 'credit');

    $this->productId = nxDnProduct($this->companyId);
    // The store a return draws from is the default one, because that is the
    // warehouse the posting names when it has to send stock back out. A receipt
    // that goes anywhere else could not be answered by a return.
    $this->warehouseId = nxDnDefaultWarehouse($this->companyId);
    $this->supplierId = nxDnSupplier($this->companyId, $this->payableAccountId);

    // The helpers below are plain functions, and a plain function cannot read
    // the trait's protected properties, so the few accounts they name are
    // copied onto ones they can.
    $this->billExpenseAccountId = $this->expenseAccountId;
    $this->invoiceRevenueAccountId = $this->revenueAccountId;
    $this->customerReceivableAccountId = $this->receivableAccountId;

    nxDnSettings($this->companyId, [
        'inventory_costing' => 'fifo',
        'allow_negative_stock' => false,
        'grni_account_id' => $this->grniAccountId,
        'purchase_variance_account_id' => $this->varianceAccountId,
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxDnMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxDnForeignCompanyId(): int
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

function nxDnForeignMember(string $role): User
{
    $companyId = nxDnForeignCompanyId();

    test()->seed(RoleAndPermissionSeeder::class);

    return nxDnMember($companyId, $role);
}

function nxDnSupplier(int $companyId, int $payableAccountId, string $code = 'S-001'): int
{
    return DB::table('suppliers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => $code,
        'name' => 'Kilimanjaro Supplies Limited',
        'currency_code' => 'TZS',
        'payable_account_id' => $payableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 45,
        'status' => 'active',
        'withholding_agent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function nxDnProduct(int $companyId, array $overrides = []): int
{
    return (int) Product::create([
        'company_id' => $companyId,
        'sku' => 'SKU-'.Str::upper(Str::random(6)),
        'name' => 'Test Widget',
        'unit' => 'pcs',
        'product_type' => 'goods',
        'inventory_account_id' => test()->inventoryAccountId,
        'expense_account_id' => test()->cogsAccountId,
        'status' => 'active',
        ...$overrides,
    ])->getKey();
}

/**
 * Naming a default demotes whichever warehouse held it, the way the warehouses
 * screen does, so the company never holds two candidates at once.
 */
function nxDnWarehouse(int $companyId, string $code, string $name, bool $isDefault = false): int
{
    if ($isDefault) {
        Warehouse::query()->where('company_id', $companyId)->update(['is_default' => false]);
    }

    return (int) Warehouse::create([
        'company_id' => $companyId,
        'code' => $code,
        'name' => $name,
        'status' => 'active',
        'is_default' => $isDefault,
    ])->getKey();
}

function nxDnDefaultWarehouse(int $companyId): int
{
    return nxDnWarehouse($companyId, 'DEFAULT', 'Main Store', true);
}

/**
 * @param  array<string, mixed>  $settings
 */
function nxDnSettings(int $companyId, array $settings): void
{
    Company::query()->whereKey($companyId)->firstOrFail()->update(['settings' => $settings]);
}

/**
 * A receipt that names the supplier, so it is met by goods received not
 * invoiced and can be cleared by a bill.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxDnReceiptBody(array $overrides = []): array
{
    return [
        'product_id' => test()->productId,
        'warehouse_id' => test()->warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '10',
        'unit_cost' => '10.0000',
        'supplier_id' => test()->supplierId,
        ...$overrides,
    ];
}

/**
 * A bill for something the company buys and does not carry: no product, so the
 * line posts straight to the expense account the caller names.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxDnExpenseBillBody(int $supplierId, array $overrides = []): array
{
    return [
        'supplier_id' => $supplierId,
        'bill_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => test()->billExpenseAccountId,
            'quantity' => '1',
            'unit_price' => '600.0000',
        ]],
        ...$overrides,
    ];
}

/**
 * A bill for an item the company carries as stock, so posting it clears goods
 * received not invoiced rather than simply debiting an expense.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxDnStockBillBody(int $supplierId, array $overrides = []): array
{
    return [
        'supplier_id' => $supplierId,
        'bill_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => test()->productId,
            'account_id' => test()->billExpenseAccountId,
            'quantity' => '10',
            'unit_price' => '10.0000',
        ]],
        ...$overrides,
    ];
}

/**
 * A bill taken as far as it can go without being posted.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function nxDnApprovedBill(array $body): array
{
    $draft = test()->postJson('/api/v1/bills', $body)->assertCreated()->json('data');

    test()->postJson('/api/v1/bills/'.$draft['ulid'].'/submit')->assertOk();

    // A bill is approved by a second pair of hands, never by the member who
    // entered it. The helper clears it under a separate member and then puts
    // the caller back, because the tests around it go on to raise the note as
    // that caller — a bill-managing role, while the approver is not.
    $enteredBy = Auth::user();
    Sanctum::actingAs(nxDnMember($enteredBy->defaultCompanyId(), AccessMatrix::ROLE_FINANCE_OFFICER));
    test()->postJson('/api/v1/bills/'.$draft['ulid'].'/approve')->assertOk();
    Sanctum::actingAs($enteredBy);

    return $draft;
}

/**
 * @return array<string, mixed>
 */
function nxDnPostBill(string $ulid): array
{
    return test()->postJson('/api/v1/bills/'.$ulid.'/post')->assertOk()->json('data');
}

/**
 * The id of the only line on a bill, read back through the API rather than
 * guessed at, because that id is what a debit note line has to name.
 */
function nxDnLineId(string $billUlid): int
{
    return (int) test()->getJson('/api/v1/bills/'.$billUlid)
        ->assertOk()
        ->json('data.lines.0.id');
}

/**
 * A debit note body debiting one line of a bill.
 *
 * @return array<string, mixed>
 */
function nxDnBody(int $lineId, string $quantity, string $date = '2026-03-20'): array
{
    return [
        'bill_date' => $date,
        'lines' => [[
            'bill_line_id' => $lineId,
            'quantity' => $quantity,
        ]],
    ];
}

/**
 * The ledger figures for one account, read back through the reporting engine
 * rather than from the tables the test just wrote.
 *
 * @return array{debit: string, credit: string, closing: string}
 */
function nxDnLedger(int $accountId): array
{
    $response = test()->getJson('/api/v1/reports/general-ledger?account_id='.$accountId)->assertOk();

    return [
        'debit' => (string) $response->json('meta.period_debit'),
        'credit' => (string) $response->json('meta.period_credit'),
        'closing' => (string) $response->json('meta.closing_balance'),
    ];
}

/**
 * What the stock layers still hold, valued at each layer's own cost.
 */
function nxDnLayerValue(): string
{
    $value = DB::table('stock_layers')
        ->selectRaw('COALESCE(SUM(quantity_remaining * unit_cost), 0) AS value')
        ->value('value');

    return number_format((float) $value, 4, '.', '');
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the debit note surface without a token', function () {
    $this->getJson('/api/v1/debit-notes')->assertUnauthorized();
});

it('lets an auditor read the debit notes without being able to raise one', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/debit-notes')->assertOk();

    // Reading is what an audit needs; correcting a purchase is what it must
    // never have, and the refusal comes before the document is even looked up.
    $this->postJson('/api/v1/bills/'.(string) Str::ulid().'/debit-notes', [])
        ->assertForbidden();
});

it('lets an auditor read a debit note without being able to post it', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/debit-notes/'.$note['ulid'])->assertOk();

    // Debiting a purchase changes the ledger, which is not the auditor's job.
    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertForbidden();
});

it('lets an auditor read a debit note without being able to reverse it', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/debit-notes/'.$note['ulid'])->assertOk();

    // Reversing a correction takes it back out of the ledger, which is as much
    // a write as posting it: an auditor must never be able to unwrite history.
    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/reverse')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Writing the draft
|--------------------------------------------------------------------------
*/

it('prices a draft debit note from the bill line it answers, not from the client', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->expenseAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]));

    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    // Only a quantity is sent, and a price beside it is ignored: the debit is
    // priced from the bill, so a client cannot debit a purchase at a figure the
    // supplier never charged.
    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', [
        'bill_date' => '2026-03-20',
        'unit_price' => '1.0000',
        'lines' => [[
            'bill_line_id' => $lineId,
            'quantity' => '2',
            'unit_price' => '1.0000',
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.document_type', 'debit_note')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.bill_no', 'DBN-000001')
        ->assertJsonPath('data.debit_note_for_bill_id', $bill['id'])
        // A debit is not a debt with terms: it falls due on its own date.
        ->assertJsonPath('data.due_date', '2026-03-20')
        ->assertJsonPath('data.subtotal', '300.0000')
        ->assertJsonPath('data.total', '300.0000')
        // Nothing reaches the ledger while the document is a draft.
        ->assertJsonPath('data.journal_id', null)
        ->assertJsonPath('data.lines.0.debit_note_for_line_id', $lineId)
        ->assertJsonPath('data.lines.0.quantity', '2.000000')
        ->assertJsonPath('data.lines.0.unit_price', '150.0000')
        ->assertJsonPath('data.lines.0.line_total', '300.0000');
});

it('refuses to debit more than the bill still owes', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->expenseAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]));

    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    // The bill is worth 600. Five units at 150 is 750, which would send the
    // payable below zero, so the note is refused.
    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '5'))
        ->assertStatus(409)
        ->assertJsonPath('error', 'BillStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'still open'));

    expect(DB::table('bills')->where('document_type', 'debit_note')->count())->toBe(0);
});

it('counts an earlier debit note against what is left to debit', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->expenseAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]));

    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    // Three of the four units come off first: 450 of the 600 is gone.
    $first = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '3'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$first['ulid'].'/post')->assertOk();

    // Only 150 is left, and two units would be 300: more than the bill still
    // owes, so it is refused.
    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '2'))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'still open'));

    // The one unit that really is left goes through.
    $second = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$second['ulid'].'/post')->assertOk();

    // The purchase is back to nil: the bill has been wholly debited.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->payableAccountId)
        ->assertOk()
        ->assertJsonPath('meta.closing_balance', '0.0000');
});

it('refuses a debit note line that is not a line of the bill', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));

    nxDnPostBill($bill['ulid']);

    // The id is real but belongs to nothing here, so the link cannot be made
    // and the note is refused.
    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody(999999, '1'))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'is not a line of'));
});

it('refuses to debit a bill that has not been posted', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // A draft has not reached the ledger, so there is nothing to correct. It is
    // edited, not debited.
    $draft = $this->postJson('/api/v1/bills', nxDnExpenseBillBody($this->supplierId))
        ->assertCreated()->json('data');

    $lineId = nxDnLineId($draft['ulid']);

    $this->postJson('/api/v1/bills/'.$draft['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertStatus(409)
        ->assertJsonPath('error', 'BillStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'has not been posted'));
});

/*
|--------------------------------------------------------------------------
| Posting: the ledger
|--------------------------------------------------------------------------
*/

it('posts a debit note that mirrors the bill entry, debiting the payable', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->expenseAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]));

    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '2'))
        ->assertCreated()->json('data');

    $posted = $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post', [], [
        'Idempotency-Key' => (string) Str::ulid(),
    ])->assertOk()->json('data');

    expect($posted['status'])->toBe('approved')
        ->and($posted['journal_id'])->toBeInt();

    // Dr the supplier's payable 300, Cr the expense 300: the mirror of the
    // bill, so each account the purchase touched is put back.
    expect(nxDnLedger($this->payableAccountId))
        ->toMatchArray(['debit' => '300.0000', 'credit' => '600.0000']);

    expect(nxDnLedger($this->expenseAccountId))
        ->toMatchArray(['debit' => '600.0000', 'credit' => '300.0000']);

    // The entry says what it is, so a report can tell a purchase correction
    // apart from a credit note against a sale.
    expect(DB::table('journals')->where('journal_type', 'debit_note')->count())->toBe(1);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('refuses to post the same debit note twice', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    // The second attempt is a collision with the state of the books, not bad
    // input, so it is a 409 and the ledger is left alone.
    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')
        ->assertStatus(409)
        ->assertJsonPath('error', 'BillStateException');

    expect(DB::table('journals')->where('journal_type', 'debit_note')->count())->toBe(1);
});

it('answers not found for a debit note raised in another company', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    Sanctum::actingAs(nxDnForeignMember(AccessMatrix::ROLE_OWNER));

    // The ULID is real, but not in the caller's company: 404 rather than a 403
    // that would confirm the note exists somewhere else.
    $this->getJson('/api/v1/debit-notes/'.$note['ulid'])->assertNotFound();
    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Immutability
|--------------------------------------------------------------------------
*/

it('has no way to change or delete a posted debit note', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    $before = DB::table('bills')->where('id', $note['id'])->first();

    // Posting is a one-way door: the URI is served by reads and by the posting
    // move, and an edit or a delete that would rewrite the ledger beneath a
    // posted document is simply not there.
    $this->patchJson('/api/v1/debit-notes/'.$note['ulid'], ['bill_date' => '2026-04-01'])->assertStatus(405);
    $this->putJson('/api/v1/debit-notes/'.$note['ulid'], ['bill_date' => '2026-04-01'])->assertStatus(405);
    $this->deleteJson('/api/v1/debit-notes/'.$note['ulid'])->assertStatus(405);

    // And the document itself is untouched: same journal, same version, same
    // date. A mistake is corrected by reversing, never by an edit.
    $after = DB::table('bills')->where('id', $note['id'])->first();

    expect($after->journal_id)->toBe($before->journal_id)
        ->and((int) $after->row_version)->toBe((int) $before->row_version)
        ->and($after->bill_date)->toBe($before->bill_date)
        ->and($after->status)->toBe('approved');
});

/*
|--------------------------------------------------------------------------
| Reversal
|--------------------------------------------------------------------------
*/

it('reverses a posted debit note, putting the stock back and netting the ledger to nothing', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxDnReceiptBody([
        'quantity' => '10',
        'unit_cost' => '10.0000',
    ]))->assertCreated();

    $bill = nxDnApprovedBill(nxDnStockBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '4'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    // Four of the ten went back before the reversal: 60 is on the shelf.
    expect(nxDnLayerValue())->toBe('60.0000')
        ->and(nxDnLedger($this->inventoryAccountId)['closing'])->toBe('60.0000');

    $reversed = $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/reverse')
        ->assertOk()
        ->json('data');

    expect($reversed['status'])->toBe('reversed');

    // The goods are back on the shelf at what they cost when they left — into a
    // new layer, because a layer is only ever written downwards — and the
    // inventory account agrees with them again.
    expect(nxDnLayerValue())->toBe('100.0000')
        ->and(nxDnLedger($this->inventoryAccountId)['closing'])->toBe('100.0000');

    // The payable the note reduced is owed again in full.
    expect(nxDnLedger($this->payableAccountId)['closing'])->toBe('-100.0000');

    // The note never touched profit, and its reversal leaves the cost account
    // where it started; goods received was cleared by the bill and stays that
    // way.
    expect(nxDnLedger($this->cogsAccountId)['closing'])->toBe('0.0000')
        ->and(nxDnLedger($this->grniAccountId)['closing'])->toBe('0.0000');

    // The entry the note posted is marked reversed and points at its reversal,
    // and the stock movement was answered by a movement of its own.
    $entry = DB::table('journals')->where('journal_type', 'debit_note')->first();

    expect($entry->status)->toBe('reversed')
        ->and($entry->reversed_by_journal_id)->not->toBeNull()
        ->and(DB::table('stock_movements')->whereNotNull('reverses_movement_id')->count())->toBe(1);

    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '100.0000')
        ->assertJsonPath('reconciled', true);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('refuses to reverse a debit note that has already been reversed', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();
    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/reverse')->assertOk();

    // A reversal is the last word: reversing the reversal would put the note
    // back into the ledger, so it is refused and the books are left alone.
    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/reverse')
        ->assertStatus(409)
        ->assertJsonPath('error', 'BillStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'already been reversed'));

    expect(DB::table('journals')->where('journal_type', 'reversal')->count())->toBe(1);
});

it('refuses to reverse a debit note that has not been posted', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    // A draft has not reached the ledger, so there is nothing to reverse.
    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/reverse')
        ->assertStatus(409)
        ->assertJsonPath('error', 'BillStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'has not been posted'));
});

it('restores what a bill still owed once the note that took it off is reversed', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $bill = nxDnApprovedBill(nxDnExpenseBillBody($this->supplierId, [
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->expenseAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]));

    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    // The whole bill is debited, so nothing is left to debit against it.
    $first = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '4'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$first['ulid'].'/post')->assertOk();

    $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '1'))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'still open'));

    // Reversing the note puts back what it took off the bill, so the bill can
    // be debited again — a reversed note is not a claim on the open amount.
    $this->postJson('/api/v1/debit-notes/'.$first['ulid'].'/reverse')->assertOk();

    $second = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '4'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$second['ulid'].'/post')->assertOk();

    expect(nxDnLedger($this->payableAccountId)['closing'])->toBe('0.0000');
});

/*
|--------------------------------------------------------------------------
| Posting: the goods go back
|--------------------------------------------------------------------------
*/

it('returns a stock part at its original receipt cost, values what is left, and leaves goods received untouched', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // 10 arrive at 10.00, met by goods received not invoiced: Dr inventory 100,
    // Cr goods received 100.
    $this->postJson('/api/v1/stock/receipts', nxDnReceiptBody([
        'quantity' => '10',
        'unit_cost' => '10.0000',
    ]))->assertCreated();

    // The bill clears that liability: Dr goods received 100, Cr payable 100.
    $bill = nxDnApprovedBill(nxDnStockBillBody($this->supplierId));

    nxDnPostBill($bill['ulid']);

    expect(GrniAllocation::query()->count())->toBe(1);

    $grniBefore = nxDnLedger($this->grniAccountId);

    $lineId = nxDnLineId($bill['ulid']);

    // Four of the ten go back. The note is priced from the bill (4 at 10.00 =
    // 40), and the goods leave at the price the receipt brought them in at.
    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '4'))
        ->assertCreated()->json('data');

    expect($note['total'])->toBe('40.0000');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    // Dr payable 40, Cr inventory 40 net. The cost of sales account is debited
    // by the issue and credited by the note, so the two cancel and the return
    // never touches profit.
    expect(nxDnLedger($this->payableAccountId))
        ->toMatchArray(['debit' => '40.0000', 'credit' => '100.0000']);

    // 100 arrived, 40 went back: 60 is left, and the layers say the same.
    $inventory = nxDnLedger($this->inventoryAccountId);

    expect($inventory['closing'])->toBe('60.0000')
        ->and(nxDnLayerValue())->toBe('60.0000');

    expect(nxDnLedger($this->cogsAccountId))
        ->toMatchArray(['debit' => '40.0000', 'credit' => '40.0000', 'closing' => '0.0000']);

    // The bill already cleared goods received, and a return does not reopen it:
    // the delivery is not "unbilled" again just because goods came back.
    expect(nxDnLedger($this->grniAccountId))->toBe($grniBefore)
        ->and(nxDnLedger($this->grniAccountId)['closing'])->toBe('0.0000')
        ->and(GrniAllocation::query()->count())->toBe(1);

    // The stock on hand is worth what the layers hold, and the report agrees.
    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '60.0000')
        ->assertJsonPath('reconciled', true);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('leaves a stock return with nothing to send back when the whole delivery goes back', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/stock/receipts', nxDnReceiptBody())->assertCreated();

    $bill = nxDnApprovedBill(nxDnStockBillBody($this->supplierId));
    nxDnPostBill($bill['ulid']);

    $lineId = nxDnLineId($bill['ulid']);

    // The whole delivery comes back: the shelf empties and the payable clears.
    $note = $this->postJson('/api/v1/bills/'.$bill['ulid'].'/debit-notes', nxDnBody($lineId, '10'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    expect(nxDnLedger($this->payableAccountId)['closing'])->toBe('0.0000')
        ->and(nxDnLedger($this->inventoryAccountId)['closing'])->toBe('0.0000')
        ->and(nxDnLayerValue())->toBe('0.0000')
        ->and(nxDnLedger($this->grniAccountId)['closing'])->toBe('0.0000');

    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '0.0000')
        ->assertJsonPath('reconciled', true);
});

/*
|--------------------------------------------------------------------------
| Ageing
|--------------------------------------------------------------------------
*/

it('nets a debit note off the payables ageing so it agrees with the payable control account', function () {
    $owner = nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER);
    $approver = nxDnMember($this->companyId, AccessMatrix::ROLE_FINANCE_OFFICER);

    Sanctum::actingAs($owner);

    $first = nxDnSupplier($this->companyId, $this->payableAccountId, 'S-A');
    $second = nxDnSupplier($this->companyId, $this->payableAccountId, 'S-B');

    $bill = function (int $supplierId, string $due, string $quantity, string $unitPrice) use ($owner, $approver): array {
        $draft = $this->postJson('/api/v1/bills', nxDnExpenseBillBody($supplierId, [
            'due_date' => $due,
            'lines' => [[
                'description' => 'Goods',
                'account_id' => $this->expenseAccountId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ]],
        ]))->assertCreated()->json('data');

        $this->postJson('/api/v1/bills/'.$draft['ulid'].'/submit')->assertOk();

        // Cleared by a second pair of hands, then the owner is put back for the
        // next bill and the note raised below.
        Sanctum::actingAs($approver);
        $this->postJson('/api/v1/bills/'.$draft['ulid'].'/approve')->assertOk();
        $this->postJson('/api/v1/bills/'.$draft['ulid'].'/post')->assertOk();
        Sanctum::actingAs($owner);

        return $draft;
    };

    // As of 2026-06-30: S-A's bill is not yet due, S-B's is 15 days late.
    $bill($first, '2026-07-31', '1', '300.0000');
    $oldest = $bill($second, '2026-06-15', '2', '100.0000');

    $lineId = nxDnLineId($oldest['ulid']);

    // Half of S-B's delivery goes back: one of the two units, 100 of the 200.
    // The note falls due on its own date (2026-03-20), so it reduces that
    // bucket rather than the one the bill it answers sits in.
    $note = $this->postJson('/api/v1/bills/'.$oldest['ulid'].'/debit-notes', nxDnBody($lineId, '1', '2026-03-20'))
        ->assertCreated()->json('data');

    expect($note['total'])->toBe('100.0000');

    $this->postJson('/api/v1/debit-notes/'.$note['ulid'].'/post')->assertOk();

    $this->getJson('/api/v1/reports/payables-ageing?as_of=2026-06-30')
        ->assertOk()
        ->assertJsonPath('data.buckets', ['current', '1_30', '31_60', '61_90', 'over_90'])
        // Two bills and the note that answers one of them.
        ->assertJsonPath('data.document_count', 3)
        ->assertJsonPath('data.totals.current', '300.0000')
        ->assertJsonPath('data.totals.1_30', '200.0000')
        // The correction shows honestly in its own bucket rather than being
        // floored at zero, which is what keeps the five columns adding up.
        ->assertJsonPath('data.totals.over_90', '-100.0000')
        ->assertJsonPath('data.totals.total', '400.0000')
        // Largest debt first: S-A still owes 300, S-B owes the 100 left of 200.
        ->assertJsonPath('data.parties.0.party_code', 'S-A')
        ->assertJsonPath('data.parties.0.total', '300.0000')
        ->assertJsonPath('data.parties.1.party_code', 'S-B')
        ->assertJsonPath('data.parties.1.total', '100.0000');

    // The report agrees with the control account it is read against: 500 was
    // raised and 100 given back, so 400 is still owed. The ledger is
    // debit-minus-credit, so a liability reads as its negative.
    expect(nxDnLedger($this->payableAccountId)['closing'])->toBe('-400.0000');
});

it('nets a credit note off the receivables ageing so it agrees with the receivable control account', function () {
    Sanctum::actingAs(nxDnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customer = fn (string $code): int => DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => $code,
        'name' => 'Acme '.$code,
        'currency_code' => 'TZS',
        'receivable_account_id' => $this->receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = function (int $customerId, string $due, string $amount): array {
        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => '2026-03-15',
            'due_date' => $due,
            'lines' => [[
                'description' => 'Consulting',
                'account_id' => $this->revenueAccountId,
                'quantity' => '1',
                'unit_price' => $amount,
            ]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertOk();

        return $draft;
    };

    // As of 2026-06-30: C-001 is not yet due, C-002 is 15 days late.
    $invoice($customer('C-001'), '2026-12-31', '300.0000');
    $late = $invoice($customer('C-002'), '2026-06-15', '200.0000');

    $lineId = (int) $this->getJson('/api/v1/invoices/'.$late['ulid'])
        ->assertOk()
        ->json('data.lines.0.id');

    // Half of the late sale is credited back. The credit falls due on its own
    // date (2026-03-20) and so reduces that bucket, not the one the invoice it
    // answers sits in.
    $note = $this->postJson('/api/v1/invoices/'.$late['ulid'].'/credit-notes', [
        'invoice_date' => '2026-03-20',
        'lines' => [['invoice_line_id' => $lineId, 'quantity' => '0.5']],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')->assertOk();

    $this->getJson('/api/v1/reports/receivables-ageing?as_of=2026-06-30')
        ->assertOk()
        ->assertJsonPath('data.document_count', 3)
        ->assertJsonPath('data.totals.current', '300.0000')
        ->assertJsonPath('data.totals.1_30', '200.0000')
        ->assertJsonPath('data.totals.over_90', '-100.0000')
        ->assertJsonPath('data.totals.total', '400.0000');

    // The report agrees with the receivable control account: 500 invoiced, 100
    // credited back, so 400 is still owed.
    expect(nxDnLedger($this->receivableAccountId)['closing'])->toBe('400.0000');
});
