<?php

use App\Domain\Access\AccessMatrix;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// A credit note is the sales side correcting its own issued invoice. It prices
// itself from the invoice line it answers, posts the mirror of that invoice's
// entry, and — where the invoice took goods off a shelf — puts them back. None
// of that can be proven around the schema, so this surface is exercised over
// the real database triggers and constraints.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // A credit note that returns goods needs the accounts an item's stock posts
    // through, and a company that costs its stock at all.
    $this->inventoryAccountId = $this->makeAccount('1200', 'Inventory', 'asset', 'debit');
    $this->cogsAccountId = $this->makeAccount('5100', 'Cost of Sales', 'expense', 'debit');
    $this->capitalAccountId = $this->makeAccount('3000', 'Owner Capital', 'equity', 'credit');

    $this->productId = nxCnProduct($this->companyId);
    // Deliberately not the default: a test that sells goods asks for the one a
    // sale draws from, so nothing is taken off a shelf by accident.
    $this->warehouseId = nxCnWarehouse($this->companyId, 'MAIN', 'Main Store');

    nxCnSettings($this->companyId, [
        'inventory_costing' => 'fifo',
        'allow_negative_stock' => false,
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxCnMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxCnForeignCompanyId(): int
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

function nxCnForeignMember(string $role): User
{
    $companyId = nxCnForeignCompanyId();

    test()->seed(RoleAndPermissionSeeder::class);

    return nxCnMember($companyId, $role);
}

function nxCnCustomer(int $companyId, int $receivableAccountId, string $code = 'C-001'): int
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
 * @param  array<string, mixed>  $overrides
 */
function nxCnProduct(int $companyId, array $overrides = []): int
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

function nxCnWarehouse(int $companyId, string $code, string $name, bool $isDefault = false): int
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

function nxCnDefaultWarehouse(int $companyId): int
{
    return nxCnWarehouse($companyId, 'DEFAULT', 'Sales Store', true);
}

/**
 * @param  array<string, mixed>  $settings
 */
function nxCnSettings(int $companyId, array $settings): void
{
    Company::query()->whereKey($companyId)->firstOrFail()->update(['settings' => $settings]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nxCnInvoice(array $overrides = []): array
{
    return test()->postJson('/api/v1/invoices', [
        'customer_id' => test()->nxCnCustomerId,
        'invoice_date' => '2026-03-15',
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * The id of the only line on an invoice, read back through the API rather than
 * guessed at, because that id is what a credit note line has to name.
 */
function nxCnLineId(string $invoiceUlid): int
{
    return (int) test()->getJson('/api/v1/invoices/'.$invoiceUlid)
        ->assertOk()
        ->json('data.lines.0.id');
}

/**
 * A credit note body crediting one line of an invoice.
 *
 * @return array<string, mixed>
 */
function nxCnBody(int $lineId, string $quantity, string $date = '2026-03-20'): array
{
    return [
        'invoice_date' => $date,
        'lines' => [[
            'invoice_line_id' => $lineId,
            'quantity' => $quantity,
        ]],
    ];
}

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the credit note surface without a token', function () {
    $this->getJson('/api/v1/credit-notes')->assertUnauthorized();
});

it('lets an auditor read the credit notes without being able to raise one', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/credit-notes')->assertOk();

    // Reading is what an audit needs; crediting a sale is what it must never
    // have, and the refusal comes before the document is even looked up.
    $this->postJson('/api/v1/invoices/'.(string) Str::ulid().'/credit-notes', [])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Writing the draft
|--------------------------------------------------------------------------
*/

it('prices a draft credit note from the invoice line it answers, not from the client', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    // Only a quantity is sent. A price here would be ignored: the credit is
    // priced from the sale, so a client cannot credit at a figure the company
    // never charged.
    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', [
        'invoice_date' => '2026-03-20',
        'unit_price' => '1.0000',
        'lines' => [[
            'invoice_line_id' => $lineId,
            'quantity' => '2',
            'unit_price' => '1.0000',
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.document_type', 'credit_note')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.invoice_no', 'CN-000001')
        ->assertJsonPath('data.credit_note_for_invoice_id', $invoice['id'])
        // A credit is not a debt with terms: it falls due on its own date.
        ->assertJsonPath('data.due_date', '2026-03-20')
        ->assertJsonPath('data.subtotal', '300.0000')
        ->assertJsonPath('data.total', '300.0000')
        // Nothing reaches the ledger while the document is a draft.
        ->assertJsonPath('data.journal_id', null)
        ->assertJsonPath('data.lines.0.credit_note_for_line_id', $lineId)
        ->assertJsonPath('data.lines.0.quantity', '2.000000')
        ->assertJsonPath('data.lines.0.unit_price', '150.0000')
        ->assertJsonPath('data.lines.0.line_total', '300.0000');
});

it('refuses to credit more of a line than was billed', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '5'))
        ->assertStatus(409)
        ->assertJsonPath('error', 'InvoiceStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'left to credit'));

    expect(DB::table('invoices')->where('document_type', 'credit_note')->count())->toBe(0);
});

it('refuses a credit note line that is not a line of the invoice', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    // The id is real but belongs to nothing here, so the link — which is what
    // the over-credit guard counts — cannot be made and the note is refused.
    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody(999999, '1'))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'is not a line of invoice'));
});

it('refuses to credit an invoice that has not been issued', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    // A draft has not reached the ledger, so there is nothing to correct. It is
    // edited, not credited.
    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $lineId = nxCnLineId($invoice['ulid']);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '1'))
        ->assertStatus(409)
        ->assertJsonPath('error', 'InvoiceStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'has not been issued'));
});

/*
|--------------------------------------------------------------------------
| Issuing: the ledger
|--------------------------------------------------------------------------
*/

it('issues a credit note and posts the mirror of the invoice entry', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    $note = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '2'))
        ->assertCreated()->json('data');

    $issued = $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue', [], [
        'Idempotency-Key' => (string) Str::ulid(),
    ])->assertOk()->json('data');

    expect($issued['status'])->toBe('issued')
        ->and($issued['journal_id'])->toBeInt()
        ->and($issued['issued_at'])->not->toBeNull();

    // Cr the customer's receivable control account 300, Dr revenue 300: the
    // mirror of the invoice, so each account the sale touched is put back.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->receivableAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '600.0000')
        ->assertJsonPath('meta.period_credit', '300.0000')
        ->assertJsonPath('meta.closing_balance', '300.0000');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->revenueAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_credit', '600.0000')
        ->assertJsonPath('meta.period_debit', '300.0000');

    // The entry says what it is, so a report can tell a correction apart.
    expect(DB::table('journals')->where('journal_type', 'credit_note')->count())->toBe(1);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('refuses to issue the same credit note twice', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    $note = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '2'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')->assertOk();

    // The second attempt is a collision with the state of the books, not bad
    // input, so it is a 409 and the ledger is left alone.
    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')
        ->assertStatus(409)
        ->assertJsonPath('error', 'InvoiceStateException');

    expect(DB::table('journals')->where('journal_type', 'credit_note')->count())->toBe(1);
});

it('refuses the issue to a cashier who may only see the document', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    $note = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '2'))
        ->assertCreated()->json('data');

    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    $this->getJson('/api/v1/credit-notes/'.$note['ulid'])->assertOk();

    // Crediting a sale changes the ledger, which is not the cashier's job.
    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')->assertForbidden();
});

it('answers not found for a credit note raised in another company', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    $note = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '2'))
        ->assertCreated()->json('data');

    Sanctum::actingAs(nxCnForeignMember(AccessMatrix::ROLE_OWNER));

    // The ULID is real, but not in the caller's company: 404 rather than a 403
    // that would confirm the note exists somewhere else.
    $this->getJson('/api/v1/credit-notes/'.$note['ulid'])->assertNotFound();
    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Issuing: the goods come back
|--------------------------------------------------------------------------
*/

it('puts a whole invoice line back by reversing the issue that took it out', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $warehouseId = nxCnDefaultWarehouse($this->companyId);

    // 10 on the shelf at 5.00, met by capital because nobody delivered them.
    $this->postJson('/api/v1/stock/receipts', [
        'product_id' => $this->productId,
        'warehouse_id' => $warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '10',
        'unit_cost' => '5.0000',
        'counter_account_id' => $this->capitalAccountId,
    ])->assertCreated();

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '100.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $issue = StockMovement::query()->where('movement_type', 'issue')->sole();

    $lineId = nxCnLineId($invoice['ulid']);

    // The whole line comes back, so the issue itself is reversed rather than a
    // second movement written beside it.
    $note = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '4'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')->assertOk();

    expect(DB::table('stock_movements')->where('id', $issue->getKey())->value('status'))->toBe('reversed')
        ->and($issue->reverses_movement_id)->toBeNull();

    // The reversal is a receipt of the same four at what they left at.
    $return = StockMovement::query()->where('reverses_movement_id', $issue->getKey())->sole();

    expect($return->movement_type->value)->toBe('receipt')
        ->and($return->quantity)->toBe('4.000000')
        ->and($return->total_cost)->toBe('20.0000');

    // Dr inventory 20, Cr cost of sales 20: the cost that left comes back, so
    // the shelf is worth what it was before the sale.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->inventoryAccountId)
        ->assertOk()
        ->assertJsonPath('meta.closing_balance', '50.0000');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cogsAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '20.0000')
        ->assertJsonPath('meta.period_credit', '20.0000')
        ->assertJsonPath('meta.closing_balance', '0.0000');

    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '50.0000')
        ->assertJsonPath('reconciled', true);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('puts a part of an invoice line back as a fresh layer at the issue cost', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $warehouseId = nxCnDefaultWarehouse($this->companyId);

    $this->postJson('/api/v1/stock/receipts', [
        'product_id' => $this->productId,
        'warehouse_id' => $warehouseId,
        'movement_date' => '2026-03-15',
        'quantity' => '10',
        'unit_cost' => '5.0000',
        'counter_account_id' => $this->capitalAccountId,
    ])->assertCreated();

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Widgets',
            'product_id' => $this->productId,
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '100.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $issue = StockMovement::query()->where('movement_type', 'issue')->sole();

    $lineId = nxCnLineId($invoice['ulid']);

    // Two of the four come back, so the issue stays posted and a fresh layer is
    // opened at the price the stock left at.
    $note = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '2'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-notes/'.$note['ulid'].'/issue')->assertOk();

    expect(DB::table('stock_movements')->where('id', $issue->getKey())->value('status'))->toBe('posted');

    // Six at 5.00 are still on the original layer and two come back on their
    // own, at the same price: the value is identical, only the layer is new.
    $return = StockMovement::query()
        ->where('movement_type', 'receipt')
        ->where('reverses_movement_id', null)
        ->where('source_type', 'App\\Models\\InvoiceLine')
        ->sole();

    expect($return->quantity)->toBe('2.000000')
        ->and($return->total_cost)->toBe('10.0000');

    expect(DB::table('stock_layers')->count())->toBe(2)
        // 10 in, 4 out on the sale, 2 back: 8 are on the shelf.
        ->and((float) DB::table('stock_layers')->sum('quantity_remaining'))->toBe(8.0);

    // Dr inventory 10, Cr cost of sales 10: the two units are worth what they
    // cost when they left.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->cogsAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '20.0000')
        ->assertJsonPath('meta.period_credit', '10.0000')
        ->assertJsonPath('meta.closing_balance', '10.0000');

    $this->getJson('/api/v1/reports/stock-valuation')
        ->assertOk()
        ->assertJsonPath('total_value', '40.0000')
        ->assertJsonPath('reconciled', true);

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true);
});

it('counts an earlier credit note against what is left to credit', function () {
    Sanctum::actingAs(nxCnMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->nxCnCustomerId = nxCnCustomer($this->companyId, $this->receivableAccountId);

    $invoice = nxCnInvoice([
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '4',
            'unit_price' => '150.0000',
        ]],
    ]);

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $lineId = nxCnLineId($invoice['ulid']);

    $first = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '3'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-notes/'.$first['ulid'].'/issue')->assertOk();

    // One is left. Asking for two is more than the sale still holds, and the
    // guard reads that from the links rather than trusting the caller.
    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '2'))
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'left to credit'));

    // The one that really is left goes through.
    $second = $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/credit-notes', nxCnBody($lineId, '1'))
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/credit-notes/'.$second['ulid'].'/issue')->assertOk();

    // Revenue is back to nil: the sale has been wholly credited.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->revenueAccountId)
        ->assertOk()
        ->assertJsonPath('meta.closing_balance', '0.0000');
});
