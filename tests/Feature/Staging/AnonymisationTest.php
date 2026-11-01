<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAccountingFixtures;

/*
 * DatabaseMigrations rather than RefreshDatabase: the command empties
 * audit_logs with TRUNCATE to rebuild the chain, and DDL causes an implicit
 * commit that would escape a wrapping transaction.
 *
 * These tests drive the command the way an operator does, and then read the
 * tables back to check what a staging copy would actually contain: no real
 * name, no real telephone number, no real tax identifier, no working
 * credential, and a ledger that does not know any of it happened.
 */
uses(DatabaseMigrations::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();
    $this->makeTradingFixtures();
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/**
 * A customer as a real one looks: a name, a telephone number and a tax
 * identifier, all of which must be gone afterwards.
 */
function nxStagingCustomer(int $companyId, int $receivableAccountId): int
{
    return DB::table('customers')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'company_id' => $companyId,
        'code' => 'CUST-001',
        'name' => 'Bahari Trading Limited',
        'tin' => '123-456-789',
        'vrn' => '11-222333-A',
        'email' => 'accounts@bahari.co.tz',
        'phone' => '+255 754 000 111',
        'address' => '12 Mkwawa Road, Dar es Salaam',
        'currency_code' => 'TZS',
        'receivable_account_id' => $receivableAccountId,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Everything the books say about money, rendered so two readings can be
 * compared as text.
 */
function nxStagingLedgerSnapshot(): string
{
    return (string) json_encode([
        'journals' => DB::table('journals')->orderBy('id')->get(),
        'lines' => DB::table('journal_lines')->orderBy('id')->get(),
        'accounts' => DB::table('accounts')->orderBy('id')->get(),
    ]);
}

/**
 * Every JSON payload the audit trail is holding, so a test can look for a name
 * inside one without guessing at the shape.
 */
function nxStagingAuditPayloads(): string
{
    return DB::table('audit_logs')
        ->orderBy('id')
        ->get()
        ->map(fn ($row): string => (string) $row->before.(string) $row->after.(string) $row->meta)
        ->implode("\n");
}

/*
|--------------------------------------------------------------------------
| The refusal
|--------------------------------------------------------------------------
*/

it('refuses to run against production', function () {
    nxStagingCustomer($this->companyId, $this->receivableAccountId);

    config(['app.env' => 'production']);

    $this->artisan('staging:anonymise', ['--force' => true])
        ->expectsOutputToContain('Refusing to run')
        ->assertExitCode(1);

    // Nothing was touched: the customer still carries the name it arrived with.
    expect(DB::table('customers')->where('name', 'Bahari Trading Limited')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| What the copy is left holding
|--------------------------------------------------------------------------
*/

it('replaces the names, contact details and tax identifiers', function () {
    $customerId = nxStagingCustomer($this->companyId, $this->receivableAccountId);

    $user = User::factory()->create([
        'name' => 'Amina Hassan',
        'email' => 'amina@nexas.co.tz',
        'phone' => '+255 754 000 222',
        'job_title' => 'Finance Manager',
    ]);

    DB::table('branches')->insert([
        'ulid' => (string) Str::ulid(),
        'company_id' => $this->companyId,
        'code' => 'HQ',
        'name' => 'Head Office',
        'address' => '12 Mkwawa Road, Dar es Salaam',
        'phone' => '+255 754 000 333',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('staging:anonymise', ['--force' => true])->assertExitCode(0);

    $company = DB::table('companies')->where('id', $this->companyId)->first();
    $customer = DB::table('customers')->where('id', $customerId)->first();
    $branch = DB::table('branches')->where('company_id', $this->companyId)->first();
    $member = DB::table('users')->where('id', $user->id)->first();

    expect($company->name)->not->toBe('Nexas Test Co')
        ->and($customer->name)->not->toBe('Bahari Trading Limited')
        ->and($customer->tin)->not->toBe('123-456-789')
        ->and($customer->vrn)->not->toBe('11-222333-A')
        ->and($customer->email)->not->toBe('accounts@bahari.co.tz')
        ->and($customer->phone)->not->toBe('+255 754 000 111')
        ->and($customer->address)->not->toBe('12 Mkwawa Road, Dar es Salaam')
        ->and($branch->name)->not->toBe('Head Office')
        ->and($branch->phone)->not->toBe('+255 754 000 333')
        ->and($member->name)->not->toBe('Amina Hassan')
        ->and($member->email)->not->toBe('amina@nexas.co.tz')
        ->and($member->phone)->not->toBe('+255 754 000 222');

    // Nothing was removed: the shape of the copy is the shape of the source,
    // only the names have moved.
    expect(DB::table('customers')->count())->toBe(1)
        ->and(DB::table('users')->count())->toBe(1)
        ->and($customer->credit_limit)->toBe('0.0000');

    // A telephone number is replaced by another telephone number, so a form
    // that validates one still accepts the copy.
    expect($member->phone)->toStartWith('+255')
        ->and($customer->tin)->toHaveLength(9);
});

it('leaves the amounts, the lines and the ledger alone', function () {
    $journalId = $this->balancedJournal('JRN-0001', '1000.0000');
    $this->postJournal($journalId);

    nxStagingCustomer($this->companyId, $this->receivableAccountId);

    $before = nxStagingLedgerSnapshot();

    $this->artisan('staging:anonymise', ['--force' => true])->assertExitCode(0);

    expect(nxStagingLedgerSnapshot())->toBe($before)
        ->and(DB::table('journals')->where('id', $journalId)->value('total_debit'))->toBe('1000.0000')
        ->and(DB::table('journals')->where('id', $journalId)->value('status'))->toBe('posted');
});

/*
|--------------------------------------------------------------------------
| Credentials
|--------------------------------------------------------------------------
*/

it('neutralises the credentials the copy arrived with', function () {
    $user = User::factory()->create([
        'name' => 'Amina Hassan',
        'email' => 'amina@nexas.co.tz',
    ]);

    $user->forceFill([
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        'two_factor_recovery_codes' => ['recovery-one', 'recovery-two'],
        'two_factor_confirmed_at' => now(),
    ])->save();

    $user->createToken('old-device');

    DB::table('password_reset_tokens')->insert([
        'email' => 'amina@nexas.co.tz',
        'token' => 'reset-token',
        'created_at' => now(),
    ]);

    DB::table('sessions')->insert([
        'id' => 'a-session-id',
        'user_id' => $user->id,
        'ip_address' => '197.186.10.4',
        'user_agent' => 'Mozilla/5.0',
        'payload' => base64_encode(serialize(['user_id' => $user->id])),
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('staging:anonymise', [
        '--force' => true,
        '--password' => 'staging-secret-2026',
    ])->assertExitCode(0);

    $member = DB::table('users')->where('id', $user->id)->first();

    expect($member->two_factor_secret)->toBeNull()
        ->and($member->two_factor_recovery_codes)->toBeNull()
        ->and($member->two_factor_confirmed_at)->toBeNull()
        ->and($member->remember_token)->toBeNull()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(0);

    // The password that was printed is the one that now opens the account, and
    // it is the only one: the hash the copy arrived with no longer verifies.
    expect(Hash::check('staging-secret-2026', $member->password))->toBeTrue()
        ->and(Hash::check('password', $member->password))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The audit trail
|--------------------------------------------------------------------------
*/

it('rebuilds the audit chain around a scrubbed trail', function () {
    $this->appendAuditRow(
        'customer.updated',
        ['name' => 'Bahari Trading Limited'],
        ['name' => 'Bahari Trading Limited', 'phone' => '+255 754 000 111'],
    );
    $this->appendAuditRow('invoice.issued', null, ['total' => '118.0000']);

    $trail = DB::table('audit_logs')->count();

    // The chain holds before anything is done to it.
    $this->artisan('audit:verify')->assertExitCode(0);

    $this->artisan('staging:anonymise', ['--force' => true])->assertExitCode(0);

    // The trail is the same length and passes its own check afterwards.
    expect(DB::table('audit_logs')->count())->toBe($trail);

    $this->artisan('audit:verify')->assertExitCode(0);

    // The name and the number are gone from inside the recorded payloads, and
    // the field names survive so the diff still says what changed.
    expect(nxStagingAuditPayloads())
        ->not->toContain('Bahari')
        ->not->toContain('+255 754 000 111')
        ->toContain('[anonymised]');

    // Where the row came from identifies a person too.
    expect(DB::table('audit_logs')->whereNotNull('ip_address')->count())->toBe(0)
        ->and(DB::table('audit_logs')->whereNotNull('user_agent')->count())->toBe(0);

    // A payload that never named anybody keeps its content: the scrubbing is
    // driven by key, not by value, so an amount stays an amount.
    expect(json_decode(DB::table('audit_logs')->where('action', 'invoice.issued')->value('after'), true))
        ->toBe(['total' => '118.0000']);
});

it('leaves the trail append-only after rebuilding it, and still growing', function () {
    $first = $this->appendAuditRow('journal.posted', ['name' => 'Bahari Trading Limited']);

    $this->artisan('staging:anonymise', ['--force' => true])->assertExitCode(0);

    // The guard trigger is back: the rebuilt trail still refuses to be edited
    // or deleted.
    expect(fn () => DB::table('audit_logs')->where('id', $first)->update(['action' => 'nothing.to.see.here']))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('audit_logs')->where('id', $first)->delete())
        ->toThrow(QueryException::class, 'append-only');

    // And a row written afterwards still chains onto the rebuilt trail.
    $second = $this->appendAuditRow('invoice.issued');

    $rows = DB::select('SELECT id, previous_hash, hash FROM audit_logs ORDER BY id ASC');

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->previous_hash)->toBe($rows[0]->hash)
        ->and((int) DB::table('audit_chain_head')->value('last_id'))->toBe($second)
        ->and((int) DB::table('audit_chain_head')->value('chain_length'))->toBe(2);

    $this->artisan('audit:verify')->assertExitCode(0);
});

/*
|--------------------------------------------------------------------------
| Running it twice
|--------------------------------------------------------------------------
*/

it('produces the same copy when it is run again', function () {
    nxStagingCustomer($this->companyId, $this->receivableAccountId);

    $this->artisan('staging:anonymise', ['--force' => true])->assertExitCode(0);

    $once = nxStagingLedgerSnapshot();
    $customer = (array) DB::table('customers')->first();

    $this->artisan('staging:anonymise', ['--force' => true])->assertExitCode(0);

    expect((array) DB::table('customers')->first())->toBe($customer)
        ->and(nxStagingLedgerSnapshot())->toBe($once);
});
