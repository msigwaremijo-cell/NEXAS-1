<?php

use App\Models\BankAccount;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

// Reconciliation compares a file a person downloaded from their bank with the
// company's own ledger. Nothing here contacts a bank and nothing here posts:
// the books already hold every movement a match can find, and matching only
// records which entry the bank's line is.
uses(RefreshDatabase::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();
    $this->makeBankAccountRecord('CRDB Current Account');

    $this->seed(RoleAndPermissionSeeder::class);

    $this->bankAccountUlid = BankAccount::query()
        ->whereKey($this->bankAccountRecordId)
        ->sole()
        ->ulid;

    // The fixture ids a global helper cannot reach, gathered once. The trait's
    // own properties are protected, so a function outside the test case cannot
    // read them; this array is the public handle onto them.
    $this->reconContext = [
        'company_id' => $this->companyId,
        'fiscal_year_id' => $this->fiscalYearId,
        'fiscal_period_id' => $this->fiscalPeriodId,
        'bank_ledger_account_id' => $this->bankLedgerAccountId,
        'bank_account_id' => $this->bankAccountRecordId,
        'contra_account_id' => $this->receivableAccountId,
    ];

    $ctx = $this->reconContext;

    // March as the company kept it: the account opened at 1,000.00, a customer
    // paid 1,500.00 in, and a supplier cheque took 400.00 out. Nothing here is
    // derived from the statement — this is the books, written independently.
    nxReconPostedJournal($ctx, '2026-03-01', '1000.0000', true, 'OPEN');

    $this->paymentJournalLineId = nxReconPostedJournal($ctx, '2026-03-15', '1500.0000', true, 'INV-1001')['bank_line_id'];
    $this->chequeJournalLineId = nxReconPostedJournal($ctx, '2026-03-18', '400.0000', false, 'CHQ-0001')['bank_line_id'];

    // The bank's own account of the same month. The first line's printed
    // balance settles backwards to the same 1,000.00 the books opened at, and
    // the last line's printed balance is the closing figure the bank wrote.
    // The charge is deliberately absent from the books.
    $this->paymentLine = nxReconLine($ctx, '2026-03-15', '0', '1500.0000', '2500.0000', 'INV-1001', 'Customer payment received');
    $this->chequeLine = nxReconLine($ctx, '2026-03-18', '400.0000', '0', '2100.0000', 'CHQ-0001', 'Supplier cheque paid');
    $this->chargeLine = nxReconLine($ctx, '2026-03-20', '12.5000', '0', '2087.5000', 'BANK-CHG', 'Monthly account charge');

    $this->actingAsOwner = nxReconMember($this->companyId, 'Owner');

    Sanctum::actingAs($this->actingAsOwner);
});

/**
 * A posted two-line journal whose bank side is on the fixture's bank ledger
 * account: money in is a debit to the asset, money out is a credit, whichever
 * way the bank prints its own columns.
 *
 * @param  array<string, int>  $ctx
 * @return array{journal_id: int, bank_line_id: int}
 */
function nxReconPostedJournal(
    array $ctx,
    string $date,
    string $amount,
    bool $moneyIn,
    ?string $reference = null,
    bool $posted = true,
    ?int $accountId = null,
): array {
    $bankAccountId = $accountId ?? $ctx['bank_ledger_account_id'];

    $journalId = DB::table('journals')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $ctx['company_id'],
        'fiscal_year_id' => $ctx['fiscal_year_id'],
        'fiscal_period_id' => $ctx['fiscal_period_id'],
        'journal_no' => 'JRN-'.Str::upper(Str::random(8)),
        'journal_type' => 'general',
        'status' => 'draft',
        'posting_date' => $date,
        'currency_code' => 'TZS',
        'reference' => $reference,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bankLineId = DB::table('journal_lines')->insertGetId([
        'company_id' => $ctx['company_id'],
        'journal_id' => $journalId,
        'line_no' => 1,
        'account_id' => $bankAccountId,
        'currency_code' => 'TZS',
        'posting_date' => $date,
        'debit' => $moneyIn ? $amount : '0',
        'credit' => $moneyIn ? '0' : $amount,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('journal_lines')->insert([
        'company_id' => $ctx['company_id'],
        'journal_id' => $journalId,
        'line_no' => 2,
        'account_id' => $ctx['contra_account_id'],
        'currency_code' => 'TZS',
        'posting_date' => $date,
        'debit' => $moneyIn ? '0' : $amount,
        'credit' => $moneyIn ? $amount : '0',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($posted) {
        DB::table('journals')->where('id', $journalId)->update([
            'status' => 'posted',
            'posted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return ['journal_id' => $journalId, 'bank_line_id' => $bankLineId];
}

/**
 * One movement as the bank printed it. debit is money that left the account and
 * credit is money that arrived, which is the opposite of the company's books.
 *
 * @param  array<string, int>  $ctx
 */
function nxReconLine(
    array $ctx,
    string $valueDate,
    string $debit = '0',
    string $credit = '0',
    ?string $balanceAfter = null,
    ?string $reference = null,
    ?string $description = null,
): int {
    return DB::table('bank_statement_lines')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $ctx['company_id'],
        'bank_account_id' => $ctx['bank_account_id'],
        'value_date' => $valueDate,
        'description' => $description,
        'reference' => $reference,
        'dedupe_hash' => hash('sha256', implode('|', [
            $valueDate, $debit, $credit, (string) $reference, (string) $description,
            (string) $ctx['company_id'], (string) $ctx['bank_account_id'],
        ])),
        'debit' => $debit,
        'credit' => $credit,
        'balance_after' => $balanceAfter,
        'status' => 'unmatched',
        'row_version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxReconMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

function nxReconRead(): TestResponse
{
    return test()->getJson('/api/v1/bank-accounts/'.test()->bankAccountUlid.'/reconciliation');
}

function nxReconMatch(int $statementLineId, array $allocations): TestResponse
{
    return test()->postJson(
        '/api/v1/bank-accounts/'.test()->bankAccountUlid.'/reconciliation/matches',
        ['statement_line_id' => $statementLineId, 'allocations' => $allocations],
    );
}

function nxReconIgnore(int $statementLineId, string $reason = 'Not part of these books'): TestResponse
{
    return test()->postJson(
        '/api/v1/bank-accounts/'.test()->bankAccountUlid.'/reconciliation/ignored-lines',
        ['statement_line_id' => $statementLineId, 'reason' => $reason],
    );
}

function nxReconComplete(): TestResponse
{
    return test()->postJson('/api/v1/bank-accounts/'.test()->bankAccountUlid.'/reconciliation/completion');
}

function nxReconAutoMatch(): TestResponse
{
    return test()->postJson('/api/v1/bank-accounts/'.test()->bankAccountUlid.'/reconciliation/auto-match');
}

/**
 * The canonical month in a state that can be signed off: both movements the
 * books hold are matched, and the charge the books do not hold is ignored.
 */
function nxReconReadyToComplete(): void
{
    nxReconMatch(test()->paymentLine, [
        ['journal_line_id' => test()->paymentJournalLineId, 'amount' => '1500.0000'],
    ]);

    nxReconMatch(test()->chequeLine, [
        ['journal_line_id' => test()->chequeJournalLineId, 'amount' => '400.0000'],
    ]);

    nxReconIgnore(test()->chargeLine, 'A bank charge the company has not entered yet');
}

it('reads the statement, the ledger and the distance between them', function (): void {
    $data = nxReconRead()->assertOk()->json('data');

    // The statement's opening is derived from its own first line, and its
    // closing is the figure the bank printed for its last one.
    expect($data['summary']['statement']['has_running_balance'])->toBeTrue()
        ->and($data['summary']['statement']['opening_balance'])->toBe('1000.0000')
        ->and($data['summary']['statement']['movement'])->toBe('1087.5000')
        ->and($data['summary']['statement']['expected_closing_balance'])->toBe('2087.5000')
        ->and($data['summary']['statement']['closing_balance'])->toBe('2087.5000')
        ->and($data['summary']['statement']['internally_consistent'])->toBeTrue()
        // The books stand at 1,000 + 1,500 - 400 = 2,100, so the bank's account
        // of the month is 12.50 short of it: the charge it took out.
        ->and($data['summary']['ledger']['balance'])->toBe('2100.0000')
        ->and($data['summary']['difference'])->toBe('-12.5000')
        ->and($data['summary']['counts']['statement_lines'])->toBe(3)
        ->and($data['summary']['counts']['outstanding'])->toBe(3)
        ->and($data['summary']['counts']['matched'])->toBe(0)
        ->and($data['summary']['completed_at'])->toBeNull()
        ->and($data['lines'])->toHaveCount(3);
});

it('matches one movement on the statement to the entry the company already made', function (): void {
    $response = nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('matched')
        ->and($response->json('data.matched_amount'))->toBe('1500.0000')
        ->and($response->json('data.matches.0.match_type'))->toBe('manual')
        ->and($response->json('data.matches.0.journal_line_id'))->toBe($this->paymentJournalLineId);

    $summary = nxReconRead()->json('data.summary');

    expect($summary['counts']['matched'])->toBe(1)
        ->and($summary['counts']['outstanding'])->toBe(2)
        ->and($summary['counts']['tentative_matches'])->toBe(1)
        // Matching changes no balance: the books already held the movement.
        ->and($summary['ledger']['balance'])->toBe('2100.0000');
});

it('splits one statement movement across several ledger entries', function (): void {
    $ctx = $this->reconContext;

    $first = nxReconPostedJournal($ctx, '2026-03-15', '1000.0000', true, 'INV-2001');
    $second = nxReconPostedJournal($ctx, '2026-03-15', '500.0000', true, 'INV-2002');

    $line = nxReconLine($ctx, '2026-03-15', '0', '1500.0000', '2500.0000', 'INV-2001', 'Receipt settled by two invoices');

    nxReconMatch($line, [
        ['journal_line_id' => $first['bank_line_id'], 'amount' => '1000.0000'],
        ['journal_line_id' => $second['bank_line_id'], 'amount' => '500.0000'],
    ])->assertCreated();

    expect(DB::table('reconciliations')->where('bank_statement_line_id', $line)->count())->toBe(2)
        ->and(DB::table('bank_statement_lines')->where('id', $line)->value('status'))->toBe('matched');

    // The line is wholly explained by the two allocations, so it is not
    // outstanding — which a line only half covered would still be.
    expect(nxReconRead()->json('data.summary.counts.outstanding'))->toBe(3);
});

it('refuses a match against a journal line on a different account', function (): void {
    $elsewhere = nxReconPostedJournal(
        $this->reconContext,
        '2026-03-15',
        '1500.0000',
        true,
        'INV-1001',
        accountId: $this->expenseAccountId,
    );

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $elsewhere['bank_line_id'], 'amount' => '1500.0000'],
    ])->assertStatus(422);
});

it('refuses a match on the wrong side of the movement', function (): void {
    // The same 1,500 on the same day, but written out of the account rather
    // than into it: a credit to the asset instead of a debit.
    $wrongSide = nxReconPostedJournal($this->reconContext, '2026-03-15', '1500.0000', false, 'INV-1001');

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $wrongSide['bank_line_id'], 'amount' => '1500.0000'],
    ])->assertStatus(422);
});

it('refuses a match against a journal that has not been posted', function (): void {
    $draft = nxReconPostedJournal(
        $this->reconContext,
        '2026-03-15',
        '1500.0000',
        true,
        'INV-1001',
        posted: false,
    );

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $draft['bank_line_id'], 'amount' => '1500.0000'],
    ])->assertStatus(422);
});

it('refuses a match that would cover more of a statement line than it moved', function (): void {
    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertCreated();

    $extra = nxReconPostedJournal($this->reconContext, '2026-03-15', '500.0000', true, 'INV-1001');

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $extra['bank_line_id'], 'amount' => '500.0000'],
    ])->assertStatus(422);
});

it('refuses a match that would spend the same ledger entry twice', function (): void {
    $second = nxReconLine($this->reconContext, '2026-03-15', '0', '1500.0000', '2500.0000', 'INV-1001', 'The same movement, printed again');

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertCreated();

    nxReconMatch($second, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertStatus(422);
});

it('refuses a second allocation naming the same ledger entry', function (): void {
    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '750.0000'],
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '750.0000'],
    ])->assertStatus(422);
});

it('releases a tentative match and puts the line back', function (): void {
    $matchId = nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertCreated()->json('data.matches.0.id');

    $this->deleteJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/reconciliation/matches/'.$matchId,
    )->assertOk()->assertJsonPath('data.status', 'unmatched');

    expect(DB::table('reconciliations')->where('id', $matchId)->exists())->toBeFalse();
});

it('ignores a statement line and keeps the reason with it', function (): void {
    nxReconIgnore($this->chargeLine, 'A bank charge the company has not entered yet')
        ->assertOk()
        ->assertJsonPath('data.status', 'ignored')
        ->assertJsonPath('data.ignored_reason', 'A bank charge the company has not entered yet');

    $summary = nxReconRead()->json('data.summary');

    // The ignored movement stays in the statement's arithmetic — the bank
    // really did take it out — and is exactly the part of the difference a
    // completed reconciliation may leave standing.
    expect($summary['counts']['ignored'])->toBe(1)
        ->and($summary['counts']['outstanding'])->toBe(2)
        ->and($summary['statement']['ignored_movement'])->toBe('-12.5000');
});

it('refuses to ignore a statement line without a reason', function (): void {
    nxReconIgnore($this->chargeLine, '   ')->assertStatus(422);
});

it('refuses to ignore a line that is still matched', function (): void {
    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertCreated();

    nxReconIgnore($this->paymentLine)->assertStatus(422);
});

it('refuses to complete while lines are still outstanding and names the distance', function (): void {
    nxReconComplete()
        ->assertStatus(409)
        ->assertJsonPath('error', 'ReconciliationStateException');

    expect(nxReconComplete()->json('message'))
        ->toContain('3')
        ->toContain('-12.5000');
});

it('refuses to complete while the difference is not explained by ignored lines', function (): void {
    // A movement the books hold that the bank has not printed: 50.00 out.
    nxReconPostedJournal($this->reconContext, '2026-03-19', '50.0000', false, 'STANDING-ORDER');

    nxReconReadyToComplete();

    // Every line is decided — none outstanding — so the only thing left is the
    // 50.00 the books moved that the bank never printed.
    nxReconComplete()->assertStatus(409);

    expect(nxReconComplete()->json('message'))->toContain('difference');
});

it('completes, stamping every match with who signed it off', function (): void {
    nxReconReadyToComplete();

    $data = nxReconComplete()->assertOk()->json('data');

    expect($data['completed_at'])->not->toBeNull()
        ->and($data['counts']['completed_matches'])->toBe(2)
        ->and($data['counts']['tentative_matches'])->toBe(0)
        ->and($data['counts']['outstanding'])->toBe(0);

    $stamps = DB::table('reconciliations')->get(['reconciled_at', 'reconciled_by_user_id']);

    expect($stamps)->toHaveCount(2);

    foreach ($stamps as $stamp) {
        expect($stamp->reconciled_at)->not->toBeNull()
            ->and((int) $stamp->reconciled_by_user_id)->toBe((int) $this->actingAsOwner->getKey());
    }
});

it('freezes a statement line a completed reconciliation locked, in the database', function (): void {
    nxReconReadyToComplete();
    nxReconComplete()->assertOk();

    // The database refuses, not just the application: a signed-off figure is
    // evidence, and what it matched cannot be edited afterwards.
    expect(fn () => DB::table('bank_statement_lines')
        ->where('id', $this->paymentLine)
        ->update(['description' => 'quietly rewritten']))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('reconciliations')
        ->where('bank_statement_line_id', $this->paymentLine)
        ->delete())
        ->toThrow(QueryException::class);
});

it('refuses a later match on a line a completed reconciliation closed', function (): void {
    nxReconReadyToComplete();
    nxReconComplete()->assertOk();

    $extra = nxReconPostedJournal($this->reconContext, '2026-03-15', '1500.0000', true, 'INV-1001');

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $extra['bank_line_id'], 'amount' => '1500.0000'],
    ])->assertStatus(409);

    $matchId = DB::table('reconciliations')->where('bank_statement_line_id', $this->paymentLine)->value('id');

    $this->deleteJson(
        '/api/v1/bank-accounts/'.$this->bankAccountUlid.'/reconciliation/matches/'.$matchId,
    )->assertStatus(409);
});

it('places the obvious movement automatically and leaves the rest for a person', function (): void {
    $data = nxReconAutoMatch()->assertOk()->json('data');

    expect(array_column($data['matched'], 'statement_line_id'))
        ->toEqualCanonicalizing([$this->paymentLine, $this->chequeLine])
        ->and(array_column($data['flagged'], 'statement_line_id'))->toBe([])
        // The charge is in the bank's file and nowhere in the books, so there is
        // nothing to propose and it comes back for a person to decide.
        ->and($data['unmatched'])->toHaveCount(1)
        ->and($data['unmatched'][0]['statement_line_id'])->toBe($this->chargeLine)
        ->and($data['unmatched'][0]['reason'])->toBe('no_candidate');

    expect(DB::table('reconciliations')->pluck('match_type')->unique()->all())->toBe(['auto']);
});

it('flags a movement two ledger entries could equally be', function (): void {
    $ctx = $this->reconContext;

    nxReconPostedJournal($ctx, '2026-03-21', '200.0000', true, 'DEP-1');
    nxReconPostedJournal($ctx, '2026-03-23', '200.0000', true, 'DEP-1');

    $line = nxReconLine($ctx, '2026-03-22', '0', '200.0000', '2300.0000', 'DEP-1', 'Deposit');

    $flagged = collect(nxReconAutoMatch()->assertOk()->json('data.flagged'))
        ->keyBy('statement_line_id');

    expect($flagged->has($line))->toBeTrue()
        ->and($flagged[$line]['reason'])->toBe('ambiguous')
        // Nothing was written for the line the matcher would not decide.
        ->and(DB::table('reconciliations')->where('bank_statement_line_id', $line)->exists())->toBeFalse();
});

it('lets an auditor read the reconciliation but not decide anything', function (): void {
    Sanctum::actingAs(nxReconMember($this->companyId, 'Auditor'));

    nxReconRead()->assertOk();

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertForbidden();

    nxReconIgnore($this->chargeLine)->assertForbidden();
    nxReconAutoMatch()->assertForbidden();
    nxReconComplete()->assertForbidden();
});

it('will not show one company reconciliation to another company member', function (): void {
    $now = now();

    $otherCompanyId = DB::table('companies')->insertGetId([
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

    $this->seed(RoleAndPermissionSeeder::class);

    Sanctum::actingAs(nxReconMember($otherCompanyId, 'Owner'));

    nxReconRead()->assertNotFound();

    nxReconMatch($this->paymentLine, [
        ['journal_line_id' => $this->paymentJournalLineId, 'amount' => '1500.0000'],
    ])->assertNotFound();
});
