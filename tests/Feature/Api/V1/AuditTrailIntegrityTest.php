<?php

use App\Domain\Access\AccessMatrix;
use App\Models\OutboxEvent;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAccountingFixtures;

/*
 * DatabaseMigrations rather than RefreshDatabase: the tamper tests run DDL to
 * drop the append-only guard trigger, and DDL causes an implicit commit that
 * would escape a wrapping transaction.
 */
uses(DatabaseMigrations::class, BuildsAccountingFixtures::class);

beforeEach(function (): void {
    $this->makeFixtures();

    // The roles are created once per company that exists when the seeder runs,
    // so it runs after the fixture company has been installed.
    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * A member of a company, holding exactly one company-scoped role.
 */
function nxAuditMember(int $companyId, string $role): User
{
    $user = User::factory()->create();

    $user->companies()->attach($companyId, ['is_default' => true, 'status' => 'active']);

    setPermissionsTeamId($companyId);
    $user->syncRoles([$role]);
    setPermissionsTeamId(null);

    return $user;
}

/*
|--------------------------------------------------------------------------
| The viewer
|--------------------------------------------------------------------------
*/

it('shows a page of the trail as verified while the chain holds', function () {
    Sanctum::actingAs(nxAuditMember($this->companyId, AccessMatrix::ROLE_OWNER));

    $this->postJson('/api/v1/users', [
        'name' => 'Amina Hassan',
        'email' => 'amina@nexas.co.tz',
        'password' => 'secret-key-2026',
    ])->assertCreated();

    Sanctum::actingAs(nxAuditMember($this->companyId, AccessMatrix::ROLE_AUDITOR));

    $response = $this->getJson('/api/v1/audit-logs')->assertOk();

    $response->assertJsonPath('meta.chain.scope', 'page')
        ->assertJsonPath('meta.chain.intact', true)
        ->assertJsonPath('meta.chain.broken_ids', [])
        ->assertJsonPath('data.0.chain_valid', true);

    expect($response->json('meta.chain.checked'))->toBeGreaterThan(0);
});

it('marks a rewritten row as broken and still shows it', function () {
    $this->appendAuditRow('journal.posted', ['status' => 'draft'], ['status' => 'posted']);
    $this->appendAuditRow('invoice.issued');

    Sanctum::actingAs(nxAuditMember($this->companyId, AccessMatrix::ROLE_OWNER));

    // An attacker with DDL rights removes the guard and rewrites history.
    DB::unprepared('DROP TRIGGER IF EXISTS `trg_audit_logs_before_update`');
    DB::table('audit_logs')->where('id', 1)->update(['action' => 'nothing.to.see.here']);

    $response = $this->getJson('/api/v1/audit-logs')->assertOk();

    $response->assertJsonPath('meta.chain.intact', false);

    expect($response->json('meta.chain.broken_ids'))->toContain(1);

    // The row is still listed. Hiding it would hide the very thing an auditor
    // came to look at.
    $broken = collect($response->json('data'))->firstWhere('id', 1);

    expect($broken)->not->toBeNull()
        ->and($broken['chain_valid'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The nightly check
|--------------------------------------------------------------------------
*/

it('schedules the nightly audit check', function () {
    $verify = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'audit:verify'));

    expect($verify)->not->toBeNull()
        ->and($verify->expression)->toBe('45 2 * * *');
});

it('writes a durable alert when the nightly check finds a break, and does not pile them up', function () {
    $this->appendAuditRow('journal.posted');
    $this->appendAuditRow('invoice.issued');

    // An intact chain is not an alert, only a clean exit code.
    $this->artisan('audit:verify')->assertExitCode(0);

    expect(OutboxEvent::query()->where('event_type', 'audit.chain_broken')->count())->toBe(0);

    DB::unprepared('DROP TRIGGER IF EXISTS `trg_audit_logs_before_update`');
    DB::table('audit_logs')->where('id', 1)->update(['action' => 'nothing.to.see.here']);

    $this->artisan('audit:verify')->assertExitCode(1);

    $event = OutboxEvent::query()->where('event_type', 'audit.chain_broken')->sole();

    expect($event->aggregate_type)->toBe('audit_chain')
        ->and($event->payload['audit_log_id'])->toBe(1)
        ->and($event->payload['company_id'])->toBe($this->companyId);

    // Still outstanding, so a second run must not add a second alert.
    $this->artisan('audit:verify')->assertExitCode(1);

    expect(OutboxEvent::query()->where('event_type', 'audit.chain_broken')->count())->toBe(1);
});

it('still alerts when the break is in the head rather than a row', function () {
    $this->appendAuditRow('journal.posted');
    $this->artisan('audit:verify')->assertExitCode(0);

    // The after-insert trigger advances the head. Dropping it leaves the head
    // behind a row that was written, which is nobody company's fault.
    DB::unprepared('DROP TRIGGER IF EXISTS `trg_audit_logs_after_insert`');
    $this->appendAuditRow('invoice.issued');

    $this->artisan('audit:verify')->assertExitCode(1);

    $event = OutboxEvent::query()->where('event_type', 'audit.chain_broken')->sole();

    expect($event->company_id)->toBeNull()
        ->and($event->payload['audit_log_id'])->toBeNull();
});

it('still alerts when the broken row belongs to no company', function () {
    // A row written outside any company, e.g. by a system action, carries no
    // company_id. Casting that to int would name company 0 and fail the outbox
    // foreign key, so the alarm would be lost on the row that needed it most.
    DB::table('audit_logs')->insert([
        'ulid' => (string) Str::ulid(),
        'company_id' => null,
        'action' => 'system.migrated',
        'created_at' => now(),
    ]);

    $this->artisan('audit:verify')->assertExitCode(0);

    DB::unprepared('DROP TRIGGER IF EXISTS `trg_audit_logs_before_update`');
    DB::table('audit_logs')->where('id', 1)->update(['action' => 'nothing.to.see.here']);

    $this->artisan('audit:verify')->assertExitCode(1);

    $event = OutboxEvent::query()->where('event_type', 'audit.chain_broken')->sole();

    expect($event->company_id)->toBeNull()
        ->and($event->payload['company_id'])->toBeNull()
        ->and($event->payload['audit_log_id'])->toBe(1);
});
