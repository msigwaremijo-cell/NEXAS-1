<?php

use App\Support\AuditChain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAccountingFixtures;

/*
 * DatabaseMigrations rather than RefreshDatabase: the tamper test has to run
 * DDL (dropping the guard trigger), and DDL causes an implicit commit that
 * would escape a wrapping transaction.
 */
uses(DatabaseMigrations::class, BuildsAccountingFixtures::class);

beforeEach(function () {
    $this->makeFixtures();
});

/**
 * Foundation test 5a: audit rows form a real hash chain.
 */
it('writes audit rows into a verifiable hash chain', function () {
    $this->appendAuditRow('journal.posted', ['status' => 'draft'], ['status' => 'posted']);
    $this->appendAuditRow('invoice.issued', null, ['total' => '118.0000']);
    $this->appendAuditRow('period.closed');

    $rows = DB::select('SELECT * FROM audit_logs ORDER BY id ASC');

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->previous_hash)->toBeNull()
        ->and($rows[1]->previous_hash)->toBe($rows[0]->hash)
        ->and($rows[2]->previous_hash)->toBe($rows[1]->hash);

    foreach ($rows as $row) {
        expect(AuditChain::hash($row))->toBe($row->hash);
    }

    $head = DB::selectOne('SELECT * FROM audit_chain_head WHERE id = 1');

    expect((int) $head->chain_length)->toBe(3)
        ->and($head->last_hash)->toBe($rows[2]->hash)
        ->and((int) $head->last_id)->toBe((int) $rows[2]->id);

    $this->artisan('audit:verify')->assertExitCode(0);
});

/**
 * Foundation test 5b: the trail is append-only.
 */
it('refuses any change to an existing audit row', function () {
    $this->appendAuditRow('journal.posted', ['status' => 'draft'], ['status' => 'posted']);

    expect(fn () => DB::table('audit_logs')->where('id', 1)->update(['action' => 'nothing.to.see.here']))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('audit_logs')->where('id', 1)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(DB::table('audit_logs')->count())->toBe(1);

    $this->artisan('audit:verify')->assertExitCode(0);
});

/**
 * Foundation test 5c: the hash chain detects tampering.
 */
it('detects tampering once the append-only guard is removed', function () {
    $this->appendAuditRow('journal.posted', ['status' => 'draft'], ['status' => 'posted']);
    $this->appendAuditRow('invoice.issued', null, ['total' => '118.0000']);

    $this->artisan('audit:verify')->assertExitCode(0);

    // An attacker with DDL rights removes the guard and rewrites history.
    DB::unprepared('DROP TRIGGER IF EXISTS `trg_audit_logs_before_update`');
    DB::table('audit_logs')->where('id', 1)->update(['action' => 'nothing.to.see.here']);

    $this->artisan('audit:verify')->assertExitCode(1);

    $tampered = DB::selectOne('SELECT * FROM audit_logs WHERE id = 1');

    expect($tampered->action)->toBe('nothing.to.see.here')
        ->and(AuditChain::hash($tampered))->not->toBe($tampered->hash);
});

it('detects a chain that no longer matches the head', function () {
    $this->appendAuditRow('journal.posted');
    $this->artisan('audit:verify')->assertExitCode(0);

    DB::unprepared('DROP TRIGGER IF EXISTS `trg_audit_logs_after_insert`');
    $this->appendAuditRow('invoice.issued');

    $this->artisan('audit:verify')->assertExitCode(1);
});
