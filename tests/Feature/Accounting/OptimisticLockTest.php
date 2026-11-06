<?php

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Purchases\Actions\ApproveBill;
use App\Domain\Purchases\Actions\CreateBill;
use App\Domain\Purchases\Actions\CreateDebitNote;
use App\Domain\Purchases\Actions\PostBill;
use App\Domain\Purchases\Actions\PostDebitNote;
use App\Domain\Purchases\Actions\ReverseDebitNote;
use App\Domain\Purchases\Actions\SubmitBillForApproval;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Sales\Actions\CreateCreditNote;
use App\Domain\Sales\Actions\CreateInvoice;
use App\Domain\Sales\Actions\IssueCreditNote;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Models\Bill;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAccountingFixtures;

/*
 * The optimistic lock is the promise that a writer who read a row and then
 * writes it is refused if somebody else moved it in between. The row_version
 * guard is the mechanism; ConcurrentModificationException::row() is the refusal
 * and the renderer in bootstrap/app.php turns it into a 409.
 *
 * Two things are proved here. First that the refusal really is a 409, by
 * driving a request whose only job is to throw it. Second that the six document
 * actions which were changed to carry the guard refuse a stale writer as a
 * whole: the exception is thrown and the transaction rolls back, so no journal,
 * no audit entry and no status change survives.
 *
 * The stale version is injected through the actions' optional expectedRowVersion
 * argument rather than by racing a second connection. It is the same mismatch a
 * genuine second writer produces — the row is no longer at the version the
 * caller believed — so what the guard does with it is exactly what it would do
 * in production.
 */
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();

    $this->customerId = nxLockCustomer($this->companyId, $this->receivableAccountId);
    $this->supplierId = nxLockSupplier($this->companyId, $this->payableAccountId);
});

/*
|--------------------------------------------------------------------------
| The refusal is an HTTP 409
|--------------------------------------------------------------------------
*/

it('renders ConcurrentModificationException::row() as an HTTP 409', function () {
    // A route whose only purpose is to throw the refusal, so the renderer in
    // bootstrap/app.php is exercised through the real HTTP kernel rather than
    // its mapping being asserted in the abstract.
    Route::get('api/__test/conflict', function (): never {
        throw ConcurrentModificationException::row('Invoice INV-000001', 7);
    });

    $this->getJson('api/__test/conflict')
        ->assertStatus(409)
        ->assertJsonPath('error', 'ConcurrentModificationException')
        ->assertJsonPath(
            'message',
            fn (string $message): bool => str_contains($message, 'expected row_version 7')
        );
});

/*
|--------------------------------------------------------------------------
| SubmitBillForApproval
|--------------------------------------------------------------------------
*/

it('refuses a submit built on a stale copy of the bill and changes nothing', function () {
    $bill = nxLockDraftBill($this->companyId, $this->supplierId, $this->expenseAccountId);

    expect(fn () => app(SubmitBillForApproval::class)($bill, null, 1))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    $row = DB::table('bills')->where('id', $bill->getKey())->first();

    // The submission is refused whole: the bill never moved behind the gate.
    expect($row->status)->toBe(BillStatus::Draft->value)
        ->and($row->approval_status)->toBe('not_required')
        ->and((int) $row->row_version)->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'bill.submitted')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| PostBill
|--------------------------------------------------------------------------
*/

it('refuses a post built on a stale copy of the bill and rolls the journal back', function () {
    $bill = nxLockApprovedBill($this->companyId, $this->supplierId, $this->expenseAccountId);

    expect(fn () => app(PostBill::class)($bill, null, null, 99))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    $row = DB::table('bills')->where('id', $bill->getKey())->first();

    // The journal was created and posted before the guard was reached, so its
    // absence afterwards is the proof that the whole transaction rolled back.
    expect((int) $row->row_version)->toBe(1)
        ->and($row->journal_id)->toBeNull()
        ->and(DB::table('journals')->count())->toBe(0)
        ->and(DB::table('journal_lines')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'bill.posted')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| IssueInvoice
|--------------------------------------------------------------------------
*/

it('refuses an issue built on a stale copy of the invoice and rolls the journal back', function () {
    $invoice = nxLockDraftInvoice($this->companyId, $this->customerId, $this->revenueAccountId);

    expect(fn () => app(IssueInvoice::class)($invoice, null, null, 1))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    $row = DB::table('invoices')->where('id', $invoice->getKey())->first();

    expect($row->status)->toBe('draft')
        ->and($row->journal_id)->toBeNull()
        ->and((int) $row->row_version)->toBe(0)
        ->and(DB::table('journals')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'invoice.issued')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| IssueCreditNote
|--------------------------------------------------------------------------
*/

it('refuses an issue built on a stale copy of the credit note and rolls the journal back', function () {
    $invoice = nxLockIssuedInvoice($this->companyId, $this->customerId, $this->revenueAccountId);

    $creditNote = app(CreateCreditNote::class)($this->companyId, $invoice, [
        'lines' => [[
            'invoice_line_id' => $invoice->lines->first()->getKey(),
            'quantity' => '1',
        ]],
    ]);

    $journalsBefore = DB::table('journals')->count();

    expect(fn () => app(IssueCreditNote::class)($creditNote, null, null, 1))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    $row = DB::table('invoices')->where('id', $creditNote->getKey())->first();

    // The credit note's own journal is the only thing the rollback had to undo:
    // the invoice it answers is untouched and still carries its one entry.
    expect($row->status)->toBe('draft')
        ->and($row->journal_id)->toBeNull()
        ->and((int) $row->row_version)->toBe(0)
        ->and(DB::table('journals')->count())->toBe($journalsBefore)
        ->and(DB::table('audit_logs')->where('action', 'credit_note.issued')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| PostDebitNote
|--------------------------------------------------------------------------
*/

it('refuses a post built on a stale copy of the debit note and rolls the journal back', function () {
    $bill = nxLockPostedBill($this->companyId, $this->supplierId, $this->expenseAccountId);

    $note = app(CreateDebitNote::class)($this->companyId, $bill, [
        'lines' => [[
            'bill_line_id' => $bill->lines->first()->getKey(),
            'quantity' => '1',
        ]],
    ]);

    $journalsBefore = DB::table('journals')->count();

    expect(fn () => app(PostDebitNote::class)($note, null, null, 1))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    $row = DB::table('bills')->where('id', $note->getKey())->first();

    // Only the bill's own entry remains: the note's journal went with the
    // rollback and the note is still an unposted draft.
    expect($row->status)->toBe(BillStatus::Draft->value)
        ->and($row->journal_id)->toBeNull()
        ->and((int) $row->row_version)->toBe(0)
        ->and(DB::table('journals')->count())->toBe($journalsBefore)
        ->and(DB::table('audit_logs')->where('action', 'debit_note.posted')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| ReverseDebitNote
|--------------------------------------------------------------------------
*/

it('refuses a reversal built on a stale copy of the debit note and leaves the books alone', function () {
    $bill = nxLockPostedBill($this->companyId, $this->supplierId, $this->expenseAccountId);

    $note = app(CreateDebitNote::class)($this->companyId, $bill, [
        'lines' => [[
            'bill_line_id' => $bill->lines->first()->getKey(),
            'quantity' => '1',
        ]],
    ]);

    $postedNote = app(PostDebitNote::class)($note);

    $noteJournalId = (int) DB::table('bills')->where('id', $note->getKey())->value('journal_id');
    $journalsBefore = DB::table('journals')->count();

    // Posting moved the note to row_version 1, so a writer still holding the
    // version it had before that is exactly the stale reader this guards.
    $stale = (int) $postedNote->row_version + 1;

    expect(fn () => app(ReverseDebitNote::class)($postedNote, null, null, null, $stale))
        ->toThrow(ConcurrentModificationException::class, 'row_version');

    $row = DB::table('bills')->where('id', $note->getKey())->first();

    // The reversal journal was written before the guard was reached, so the
    // rollback has to have taken it back out and left the note's own entry
    // posted rather than reversed.
    expect($row->status)->toBe(BillStatus::Approved->value)
        ->and((int) $row->row_version)->toBe(1)
        ->and(DB::table('journals')->count())->toBe($journalsBefore)
        ->and(DB::table('journals')->where('id', $noteJournalId)->value('status'))->toBe('posted')
        ->and(DB::table('audit_logs')->where('action', 'debit_note.reversed')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| The documents are built through the same create/issue actions the API drives,
| so the tests stand on a fully priced document rather than a hand-made row.
*/

function nxLockCustomer(int $companyId, int $receivableAccountId): int
{
    return DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => 'C-001',
        'name' => 'Serengeti Retail Limited',
        'currency_code' => 'TZS',
        'receivable_account_id' => $receivableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 30,
        'status' => 'active',
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nxLockSupplier(int $companyId, int $payableAccountId): int
{
    return DB::table('suppliers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => 'S-001',
        'name' => 'Kilimanjaro Supplies Limited',
        'currency_code' => 'TZS',
        'payable_account_id' => $payableAccountId,
        'credit_limit' => '0',
        'payment_terms_days' => 45,
        'status' => 'active',
        'withholding_agent' => false,
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function nxLockDraftBill(int $companyId, int $supplierId, int $expenseAccountId): Bill
{
    return app(CreateBill::class)($companyId, $supplierId, [
        'bill_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Office stationery',
            'account_id' => $expenseAccountId,
            'quantity' => '1',
            'unit_price' => '300.0000',
        ]],
    ]);
}

function nxLockApprovedBill(int $companyId, int $supplierId, int $expenseAccountId): Bill
{
    $bill = nxLockDraftBill($companyId, $supplierId, $expenseAccountId);

    DB::table('bills')->where('id', $bill->getKey())->update([
        'status' => BillStatus::PendingApproval->value,
        'updated_at' => now(),
    ]);

    return app(ApproveBill::class)($bill);
}

function nxLockPostedBill(int $companyId, int $supplierId, int $expenseAccountId): Bill
{
    return app(PostBill::class)(nxLockApprovedBill($companyId, $supplierId, $expenseAccountId));
}

function nxLockDraftInvoice(int $companyId, int $customerId, int $revenueAccountId): Invoice
{
    return app(CreateInvoice::class)($companyId, $customerId, [
        'invoice_date' => '2026-03-15',
        'lines' => [[
            'description' => 'Consulting services',
            'account_id' => $revenueAccountId,
            'quantity' => '1',
            'unit_price' => '500.0000',
        ]],
    ]);
}

function nxLockIssuedInvoice(int $companyId, int $customerId, int $revenueAccountId): Invoice
{
    return app(IssueInvoice::class)(nxLockDraftInvoice($companyId, $customerId, $revenueAccountId));
}
