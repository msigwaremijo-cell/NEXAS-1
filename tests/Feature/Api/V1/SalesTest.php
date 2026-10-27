<?php

use App\Domain\Access\AccessMatrix;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Invoices and receipts are stored in MySQL and their journal is derived by the
// database triggers, so this surface is exercised over the real schema rather
// than around it.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    // A receipt names one of the company's own cash or bank accounts rather
    // than a raw ledger account, so the fixtures provide one.
    $this->makeBankAccountRecord();

    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxSalesMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/**
 * A second tenant, used to prove nothing in the caller's company can be
 * reached by naming something in another one.
 */
function nxSalesForeignCompanyId(): int
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
 * A fully privileged member of a second tenant.
 *
 * Roles are created once per company that exists when the seeder runs, so the
 * foreign company has to be inserted and the seeder re-run before anybody can
 * hold a role inside it. Without that second run syncRoles() fails with
 * "there is no role named Owner", which is a defect in the fixture rather than
 * in the application.
 */
function nxSalesForeignMember(string $role): User
{
    $companyId = nxSalesForeignCompanyId();

    test()->seed(RoleAndPermissionSeeder::class);

    return nxSalesMember($companyId, $role);
}

/**
 * A customer row, written directly so a test can pin the payment terms that
 * decide an invoice's due date.
 */
function nxSalesCustomer(
    int $companyId,
    int $receivableAccountId,
    string $code = 'C-001',
    int $terms = 30,
): int {
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

/*
|--------------------------------------------------------------------------
| Access
|--------------------------------------------------------------------------
*/

it('refuses the sales surface without a token', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
})->with([
    '/api/v1/customers',
    '/api/v1/invoices',
    '/api/v1/payments',
    '/api/v1/reports/receivables-ageing',
]);

it('lets an auditor read the invoices without being able to raise one', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $this->getJson('/api/v1/invoices')->assertOk();

    // Reading is what an audit needs; writing is what it must never have.
    $this->postJson('/api/v1/invoices', [])->assertForbidden();
    $this->postJson('/api/v1/customers', [])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Customers
|--------------------------------------------------------------------------
*/

it('creates a customer in the caller company and lists it back', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/customers', [
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'receivable_account_id' => $this->receivableAccountId,
        'payment_terms_days' => 30,
    ])
        ->assertCreated()
        ->assertJsonPath('data.code', 'C-001')
        ->assertJsonPath('data.company_id', $this->companyId)
        ->assertJsonPath('data.receivable_account_id', $this->receivableAccountId)
        // Taken from the company rather than left to the caller.
        ->assertJsonPath('data.currency_code', 'TZS')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.credit_limit', '0.0000');

    $this->getJson('/api/v1/customers')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'C-001');
});

it('refuses a customer code already used in the same company', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    nxSalesCustomer($this->companyId, $this->receivableAccountId, 'C-001');

    $this->postJson('/api/v1/customers', [
        'code' => 'C-001',
        'name' => 'A second Acme',
        'receivable_account_id' => $this->receivableAccountId,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('refuses to point a customer at another company ledger account', function () {
    $now = now();

    $foreignAccountId = DB::table('accounts')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => nxSalesForeignCompanyId(),
        'code' => '1100',
        'name' => 'Accounts Receivable',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'depth' => 0,
        'is_postable' => true,
        'is_cash' => false,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // The account exists, but not in the caller's books. Accepting it would
    // make every invoice for this customer post into another tenant's ledger.
    $this->postJson('/api/v1/customers', [
        'code' => 'C-001',
        'name' => 'Acme Limited',
        'receivable_account_id' => $foreignAccountId,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('receivable_account_id');
});

/*
|--------------------------------------------------------------------------
| Invoices
|--------------------------------------------------------------------------
*/

it('prices a draft invoice itself and ignores a total sent by the client', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId, 'C-001', 30);

    $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        // A client may send whatever total it likes. The column is not
        // fillable, so this figure is ignored and the document is priced from
        // its lines instead.
        'total' => '1.0000',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '2',
            'unit_price' => '150.0000',
        ]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.invoice_no', 'INV-000001')
        ->assertJsonPath('data.subtotal', '300.0000')
        ->assertJsonPath('data.total', '300.0000')
        ->assertJsonPath('data.amount_paid', '0.0000')
        ->assertJsonPath('data.outstanding', '300.0000')
        // Nothing reaches the ledger while the document is a draft.
        ->assertJsonPath('data.journal_id', null)
        // 2026-03-15 plus the customer's 30 days of terms, not a date the
        // caller supplied.
        ->assertJsonPath('data.due_date', '2026-04-14')
        ->assertJsonPath('data.lines.0.line_total', '300.0000');
});

it('refuses an invoice line that names no revenue account', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'quantity' => '1',
            'unit_price' => '100.0000',
        ]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.account_id');
});

it('issues an invoice and posts a balanced journal to the ledger', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

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

    $issued = $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue', [], [
        'Idempotency-Key' => (string) Str::ulid(),
    ])->assertOk()->json('data');

    expect($issued['status'])->toBe('issued')
        ->and($issued['journal_id'])->toBeInt()
        ->and($issued['issued_at'])->not->toBeNull();

    // Dr the customer's receivable control account, Cr revenue.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->receivableAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '300.0000')
        ->assertJsonPath('meta.period_credit', '0.0000')
        ->assertJsonPath('meta.closing_balance', '300.0000');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->revenueAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_credit', '300.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true)
        ->assertJsonPath('meta.total_debit', '300.0000')
        ->assertJsonPath('meta.total_credit', '300.0000');
});

it('keeps a draft invoice out of the ledger', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated();

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->receivableAccountId)
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.closing_balance', '0.0000');

    $this->getJson('/api/v1/reports/trial-balance')
        ->assertOk()
        ->assertJsonPath('meta.balanced', true)
        ->assertJsonPath('meta.total_debit', '0.0000');
});

it('refuses to issue the same invoice twice', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

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

    // The second attempt is a collision with the state of the books, not bad
    // input, so it is a 409 and the ledger is left alone.
    $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')
        ->assertStatus(409)
        ->assertJsonPath('error', 'InvoiceStateException');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->receivableAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '300.0000');
});

it('refuses the issue to a cashier who may only take the money', function () {
    $owner = nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

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

    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_CASHIER));

    // The cashier may see the document they are collecting against...
    $this->getJson('/api/v1/invoices/'.$draft['ulid'])->assertOk();

    // ...but issuing it changes the ledger, which is not their job.
    $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertForbidden();
});

it('answers not found for an invoice raised in another company', function () {
    $owner = nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER);

    Sanctum::actingAs($owner);

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

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

    Sanctum::actingAs(nxSalesForeignMember(AccessMatrix::ROLE_OWNER));

    // The ULID is real, but not in the caller's company: 404 rather than a 403
    // that would confirm the invoice exists somewhere else.
    $this->getJson('/api/v1/invoices/'.$draft['ulid'])->assertNotFound();
    $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Receipts
|--------------------------------------------------------------------------
*/

it('applies a receipt to an invoice and nets the receivable to nil', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    $invoice = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    // party_type is left out on purpose: a receipt can only be from a customer,
    // so the controller derives it from the direction.
    $this->postJson('/api/v1/payments', [
        'direction' => 'receipt',
        'party_id' => $customerId,
        'bank_account_id' => $this->bankAccountRecordId,
        'payment_date' => '2026-03-20',
        'amount' => '300.0000',
        'method' => 'bank',
        'allocations' => [[
            'document_type' => 'invoice',
            'document_id' => $invoice['ulid'],
            'amount' => '300.0000',
        ]],
    ], ['Idempotency-Key' => (string) Str::ulid()])
        ->assertCreated()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.party_type', 'customer')
        // The receipt remembers which of the company's accounts the money went
        // into, not just the ledger account behind it.
        ->assertJsonPath('data.bank_account_id', $this->bankAccountRecordId)
        ->assertJsonPath('data.amount', '300.0000')
        ->assertJsonPath('data.allocated_amount', '300.0000')
        ->assertJsonPath('data.unapplied_amount', '0.0000');

    $this->getJson('/api/v1/invoices/'.$invoice['ulid'])
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.amount_paid', '300.0000')
        ->assertJsonPath('data.outstanding', '0.0000');

    // Dr bank 300, Cr receivable 300, on top of the 300 the issue debited: the
    // control account is back to nothing.
    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->receivableAccountId)
        ->assertOk()
        ->assertJsonPath('meta.closing_balance', '0.0000');

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->bankLedgerAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '300.0000');

    $this->getJson('/api/v1/reports/receivables-ageing?as_of=2026-03-20')
        ->assertOk()
        ->assertJsonPath('data.document_count', 0)
        ->assertJsonPath('data.totals.total', '0.0000');
});

it('replays a receipt carrying the same idempotency key instead of crediting twice', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    $invoice = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    $key = (string) Str::ulid();

    $payload = [
        'direction' => 'receipt',
        'party_id' => $customerId,
        'bank_account_id' => $this->bankAccountRecordId,
        'payment_date' => '2026-03-20',
        'amount' => '300.0000',
        'allocations' => [[
            'document_type' => 'invoice',
            'document_id' => $invoice['ulid'],
            'amount' => '300.0000',
        ]],
    ];

    $first = $this->postJson('/api/v1/payments', $payload, ['Idempotency-Key' => $key])
        ->assertCreated()
        ->json('data');

    // The client timed out and retried. It gets the original receipt back, not
    // a second one: a customer must never be credited twice.
    $second = $this->postJson('/api/v1/payments', $payload, ['Idempotency-Key' => $key])
        ->assertCreated()
        ->json('data');

    expect($second['id'])->toBe($first['id'])
        ->and($second['payment_no'])->toBe($first['payment_no'])
        ->and(DB::table('payments')->where('company_id', $this->companyId)->count())->toBe(1);

    $this->getJson('/api/v1/reports/general-ledger?account_id='.$this->bankLedgerAccountId)
        ->assertOk()
        ->assertJsonPath('meta.period_debit', '300.0000');
});

it('refuses an allocation larger than what the invoice still owes', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    $invoice = $this->postJson('/api/v1/invoices', [
        'customer_id' => $customerId,
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting',
            'account_id' => $this->revenueAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ])->assertCreated()->json('data');

    $this->postJson('/api/v1/invoices/'.$invoice['ulid'].'/issue')->assertOk();

    // 400 against a 300 invoice: the receipt itself is valid (the money could
    // all have arrived), it is the allocation that overstates the debt.
    $this->postJson('/api/v1/payments', [
        'direction' => 'receipt',
        'party_id' => $customerId,
        'bank_account_id' => $this->bankAccountRecordId,
        'payment_date' => '2026-03-20',
        'amount' => '400.0000',
        'allocations' => [[
            'document_type' => 'invoice',
            'document_id' => $invoice['ulid'],
            'amount' => '400.0000',
        ]],
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'PaymentStateException');

    // The whole action runs in one transaction, so a refused allocation leaves
    // neither a payment nor a touched invoice behind.
    expect(DB::table('payments')->where('company_id', $this->companyId)->count())->toBe(0);
    expect(DB::table('invoices')->where('ulid', $invoice['ulid'])->value('amount_paid'))->toBe('0.0000');
});

it('refuses a receipt that names a supplier as the party', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    // Money coming in can only be from a customer. The controller would have
    // derived the type from the direction; naming the wrong one is refused.
    $this->postJson('/api/v1/payments', [
        'direction' => 'receipt',
        'party_type' => 'supplier',
        'party_id' => $customerId,
        'bank_account_id' => $this->bankAccountRecordId,
        'payment_date' => '2026-03-20',
        'amount' => '100.0000',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'PaymentStateException');
});

it('refuses a receipt into a bank account that has been closed', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId);

    // The account was opened and has since been closed. Its history stays where
    // it is, but new money must not be posted through it.
    DB::table('bank_accounts')
        ->where('id', $this->bankAccountRecordId)
        ->update(['status' => 'closed']);

    $this->postJson('/api/v1/payments', [
        'direction' => 'receipt',
        'party_id' => $customerId,
        'bank_account_id' => $this->bankAccountRecordId,
        'payment_date' => '2026-03-20',
        'amount' => '100.0000',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'PaymentStateException')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Closed'));

    // Refused before anything was written: no receipt and no journal.
    expect(DB::table('payments')->where('company_id', $this->companyId)->count())->toBe(0)
        ->and(DB::table('journals')->where('company_id', $this->companyId)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Receivables ageing
|--------------------------------------------------------------------------
*/

it('buckets receivables by how late they are and drops what has been paid', function () {
    Sanctum::actingAs(nxSalesMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // Every invoice is raised inside the one open period the fixtures install;
    // only the due date moves, which is what the buckets are measured from.
    $specs = [
        ['code' => 'C-001', 'amount' => '100.0000', 'due' => '2026-12-31'], // not yet due
        ['code' => 'C-002', 'amount' => '200.0000', 'due' => '2026-12-10'], // 21 days late
        ['code' => 'C-003', 'amount' => '300.0000', 'due' => '2026-09-20'], // 102 days late
    ];

    $invoices = [];

    foreach ($specs as $spec) {
        $customerId = nxSalesCustomer($this->companyId, $this->receivableAccountId, $spec['code']);

        $draft = $this->postJson('/api/v1/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => '2026-03-15',
            'due_date' => $spec['due'],
            'lines' => [[
                'description' => 'Consulting',
                'account_id' => $this->revenueAccountId,
                'quantity' => '1',
                'unit_price' => $spec['amount'],
            ]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/invoices/'.$draft['ulid'].'/issue')->assertOk();

        $invoices[$spec['code']] = $draft;
    }

    $this->getJson('/api/v1/reports/receivables-ageing?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.as_of', '2026-12-31')
        ->assertJsonPath('data.document_count', 3)
        ->assertJsonPath('data.totals.current', '100.0000')
        ->assertJsonPath('data.totals.1_30', '200.0000')
        ->assertJsonPath('data.totals.31_60', '0.0000')
        ->assertJsonPath('data.totals.61_90', '0.0000')
        ->assertJsonPath('data.totals.over_90', '300.0000')
        ->assertJsonPath('data.totals.total', '600.0000')
        // Largest debt first: an ageing report is read from the top, and the
        // party that owes the most is the one being chased.
        ->assertJsonPath('data.parties.0.party_code', 'C-003')
        ->assertJsonPath('data.parties.0.total', '300.0000');

    // Settling the smallest invoice takes it out of the report entirely: once
    // paid it expects nothing, so it has no place in an ageing of what is owed.
    $this->postJson('/api/v1/payments', [
        'direction' => 'receipt',
        'party_id' => (int) $invoices['C-001']['customer_id'],
        'bank_account_id' => $this->bankAccountRecordId,
        'payment_date' => '2026-03-20',
        'amount' => '100.0000',
        'allocations' => [[
            'document_type' => 'invoice',
            'document_id' => $invoices['C-001']['ulid'],
            'amount' => '100.0000',
        ]],
    ])->assertCreated();

    $this->getJson('/api/v1/reports/receivables-ageing?as_of=2026-12-31')
        ->assertOk()
        ->assertJsonPath('data.document_count', 2)
        ->assertJsonPath('data.totals.current', '0.0000')
        ->assertJsonPath('data.totals.total', '500.0000');
});
