<?php

use App\Domain\Backups\Actions\CreateBackup;
use App\Domain\Backups\Actions\PruneBackups;
use App\Domain\Backups\Actions\RestoreBackup;
use App\Domain\Backups\Enums\BackupStatus;
use App\Domain\Backups\Exceptions\BackupRefusedException;
use App\Domain\Backups\Exceptions\BackupsException;
use App\Domain\Backups\Services\BackupBundle;
use App\Domain\Backups\Services\BackupCipher;
use App\Models\Backup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// A backup is one encrypted file holding the whole database and the documents
// filed against it, taken a night at a time and proven restorable. These tests
// cover the four things that makes it worth having: it is written, it is not
// written without a key, the retention policy can never take the last one, and
// a restore either reproduces the books exactly or refuses.
//
// Where a test needs a real dump and a real load it uses the MySQL client
// tools the application itself uses, and skips with a clear reason where they
// are not installed — the mechanics are then "not verified" on that machine
// rather than silently assumed.
//
// The refusals do not need the tools. A wrong key or an altered file stops at
// the cipher, before a scratch database is ever created, so those tests run
// everywhere and assert the thing that matters: nothing was restored.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The backup and document disks are faked so a test never writes into the
    // real store while still exercising the same store/read/delete code path.
    Storage::fake('backups');
    Storage::fake('attachments');
    Storage::fake('statements');

    // A key of the test's own, so nothing here depends on the machine's .env.
    config(['nexas.backups.key' => nxBackupKey()]);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/**
 * A backup key, as base64 of exactly 32 bytes.
 */
function nxBackupKey(int $byte = 7): string
{
    return 'base64:'.base64_encode(str_repeat(chr($byte), 32));
}

/**
 * A MySQL client binary, or null when it is not on this machine.
 *
 * A configured full path is checked directly; a bare name is looked for along
 * PATH, which is where a deployment is expected to keep it.
 */
function nxBackupBinary(string $which): ?string
{
    $configured = (string) config('nexas.backups.'.$which, $which);

    if ($configured !== $which) {
        return is_file($configured) ? $configured : null;
    }

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory === '') {
            continue;
        }

        foreach ([$configured, $configured.'.exe', $configured.'.bat', $configured.'.cmd'] as $candidate) {
            $path = $directory.DIRECTORY_SEPARATOR.$candidate;

            if (is_file($path)) {
                return $path;
            }
        }
    }

    return null;
}

/**
 * Skip a test that needs the client tools on a machine that has none.
 */
function nxBackupRequireMysqlTools(): void
{
    if (nxBackupBinary('mysqldump') === null || nxBackupBinary('mysql') === null) {
        test()->markTestSkipped(
            'The MySQL client tools are not installed here, so the dump and the restore are not verified on this machine.'
        );
    }
}

/**
 * Whether a database exists, asked through the application's own connection so
 * a test does not need the client tools to answer it.
 */
function nxBackupDatabaseExists(string $database): bool
{
    return (int) DB::selectOne(
        'SELECT COUNT(*) AS found FROM information_schema.schemata WHERE schema_name = ?',
        [$database]
    )->found > 0;
}

/**
 * A finished backup whose file is really on the fake disk: a bundle written
 * from the given parts, encrypted under the given key.
 *
 * @param  array<string, string>  $parts  part name => contents
 */
function nxBackupCrafted(string $key, array $parts): Backup
{
    $scratch = sys_get_temp_dir().'/nexas_test_backup_'.Str::ulid();

    File::ensureDirectoryExists($scratch);

    try {
        $sources = [];

        foreach ($parts as $name => $contents) {
            $source = $scratch.'/src_'.str_replace('/', '_', $name);

            file_put_contents($source, $contents);

            $sources[$name] = $source;
        }

        $manifest = (new BackupBundle)->write($sources, $scratch.'/bundle');

        $fileName = 'nexas-test-'.Str::ulid().'.nxb';

        BackupCipher::fromKeyString($key)->encrypt($scratch.'/bundle', $scratch.'/'.$fileName);

        $path = '2026/10/'.$fileName;
        $encrypted = (string) file_get_contents($scratch.'/'.$fileName);

        Storage::disk('backups')->put($path, $encrypted);
    } finally {
        File::deleteDirectory($scratch);
    }

    $backup = Backup::create([
        'disk' => 'backups',
        'status' => BackupStatus::Running,
        'database_name' => (string) config('database.connections.'.config('database.default').'.database'),
        'started_at' => now(),
    ]);

    $backup->markCompleted(
        path: $path,
        fileName: $fileName,
        sizeBytes: strlen($encrypted),
        checksum: hash('sha256', $encrypted),
        tableCount: count($manifest),
        rowCount: 0,
        fileCount: count($manifest) - 1,
    );

    return $backup;
}

/**
 * A finished backup taken this many days ago, with its file on the fake disk.
 */
function nxBackupHeld(int $daysAgo): Backup
{
    $fileName = 'nexas-'.Str::ulid().'.nxb';
    $path = '2026/10/'.$fileName;

    Storage::disk('backups')->put($path, 'encrypted');

    $backup = Backup::create([
        'disk' => 'backups',
        'status' => BackupStatus::Running,
        'database_name' => 'nexas_test',
        'started_at' => now()->subDays($daysAgo + 1),
    ]);

    $backup->markCompleted(
        path: $path,
        fileName: $fileName,
        sizeBytes: 9,
        checksum: str_repeat('a', 64),
        tableCount: 1,
        rowCount: 0,
        fileCount: 0,
    );

    // markCompleted stamps the moment of completion; put it back to the age the
    // test asked for so the retention window has something to compare against.
    $backup->forceFill(['completed_at' => now()->subDays($daysAgo)])->save();

    return $backup;
}

/*
|--------------------------------------------------------------------------
| Taking one
|--------------------------------------------------------------------------
*/

it('writes one encrypted backup holding the database and the filed documents', function () {
    nxBackupRequireMysqlTools();

    Storage::disk('attachments')->put('1/01J0/scan.pdf', '%PDF-1.4 fake invoice');
    Storage::disk('statements')->put('1/2026/10/bank.csv', "date,amount\n2026-10-01,100\n");

    $this->artisan('backups:run')->assertSuccessful();

    $backup = Backup::query()->sole();

    expect($backup->status)->toBe(BackupStatus::Completed)
        ->and($backup->disk)->toBe('backups')
        ->and($backup->path)->not->toBeNull()
        ->and($backup->file_name)->toEndWith('.nxb')
        ->and($backup->size_bytes)->toBeGreaterThan(0)
        ->and($backup->checksum_sha256)->toHaveLength(64)
        ->and($backup->table_count)->toBeGreaterThan(0)
        ->and($backup->row_count)->toBeGreaterThanOrEqual(0)
        // Both filed documents travelled, and nothing from the exports disk
        // (which is not in the configured list) did.
        ->and($backup->file_count)->toBe(2)
        ->and($backup->completed_at)->not->toBeNull()
        ->and($backup->error)->toBeNull();

    // What is on the disk is the encrypted file, not the books written out.
    $stored = (string) Storage::disk('backups')->get((string) $backup->path);

    expect($stored)->toStartWith("NXBK1\0")
        ->and($stored)->not->toContain('CREATE TABLE')
        ->and(hash('sha256', $stored))->toBe($backup->checksum_sha256);
});

it('refuses to write without a key and leaves no row and no file behind', function () {
    config(['nexas.backups.key' => null]);

    $this->artisan('backups:run')
        ->assertFailed()
        ->expectsOutputToContain('No backup key is configured');

    // Loudly, and before a row was written: a night that could never have
    // produced a backup is not recorded as an attempt.
    expect(Backup::query()->count())->toBe(0)
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

it('marks the run failed with the reason when the dump cannot be taken', function () {
    // A mysqldump that is not there: the run cannot produce a backup, so it
    // says so and leaves the row marked rather than sitting on running.
    config(['nexas.backups.mysqldump' => '/no/such/mysqldump-binary']);

    expect(fn () => app(CreateBackup::class)())->toThrow(BackupsException::class);

    $backup = Backup::query()->sole();

    expect($backup->status)->toBe(BackupStatus::Failed)
        ->and($backup->error)->toBeString()->not->toBe('')
        ->and($backup->path)->toBeNull()
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
*/

it('prunes files past retention but never takes the newest held', function () {
    config([
        'nexas.backups.retention_days' => 1,
        'nexas.backups.keep' => 2,
    ]);

    $recent = nxBackupHeld(0);
    $yesterday = nxBackupHeld(2);
    $older = nxBackupHeld(4);
    $oldest = nxBackupHeld(6);

    $recentPath = (string) $recent->path;
    $olderPath = (string) $older->path;
    $oldestPath = (string) $oldest->path;

    $pruned = app(PruneBackups::class)();

    // The two newest are held whatever their age — the second of them is past
    // the window and survives only because of the floor. The other two go.
    expect($pruned)->toBe(2)
        ->and($recent->refresh()->path)->toBe($recentPath)
        ->and($yesterday->refresh()->path)->not->toBeNull()
        ->and($older->refresh()->path)->toBeNull()
        ->and($oldest->refresh()->path)->toBeNull()
        ->and($older->refresh()->pruned_at)->not->toBeNull()
        ->and(Storage::disk('backups')->exists($recentPath))->toBeTrue()
        ->and(Storage::disk('backups')->exists($olderPath))->toBeFalse()
        ->and(Storage::disk('backups')->exists($oldestPath))->toBeFalse();

    // The row stays: that a copy was taken on that night outlives the copy.
    expect(Backup::query()->count())->toBe(4);
});

it('leaves backups inside the retention window alone', function () {
    config([
        'nexas.backups.retention_days' => 14,
        'nexas.backups.keep' => 1,
    ]);

    nxBackupHeld(1);
    nxBackupHeld(3);
    nxBackupHeld(9);

    expect(app(PruneBackups::class)())->toBe(0)
        ->and(Backup::query()->restorable()->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Restoring — the refusals
|--------------------------------------------------------------------------
*/

it('refuses to restore into the database the application is reading', function () {
    $backup = nxBackupHeld(0);

    $live = (string) config('database.connections.'.config('database.default').'.database');

    expect(fn () => app(RestoreBackup::class)($backup, $live))
        ->toThrow(BackupRefusedException::class, 'that is the database this application is reading');
});

it('refuses to restore into a database that is not named as a scratch one', function () {
    $backup = nxBackupHeld(0);

    expect(fn () => app(RestoreBackup::class)($backup, 'nexas_books'))
        ->toThrow(BackupRefusedException::class, 'a restore only ever writes into a scratch database');
});

it('refuses to restore a backup that is not finished', function () {
    $running = Backup::create([
        'disk' => 'backups',
        'status' => BackupStatus::Running,
        'database_name' => 'nexas_test',
        'started_at' => now(),
    ]);

    expect(fn () => app(RestoreBackup::class)($running, 'nexas_restore_unfinished'))
        ->toThrow(BackupRefusedException::class, 'cannot be restored');
});

it('refuses a backup opened under the wrong key, before anything is restored', function () {
    $backup = nxBackupCrafted(nxBackupKey(), ['database.sql' => "-- the dump\nSELECT 1;\n"]);

    expect(fn () => app(RestoreBackup::class)($backup, 'nexas_restore_wrongkey', nxBackupKey(99)))
        ->toThrow(BackupRefusedException::class, 'does not authenticate');

    // The refusal came at the cipher, so no scratch database was ever created.
    expect(nxBackupDatabaseExists('nexas_restore_wrongkey'))->toBeFalse();
});

it('refuses a backup with a single changed byte, before anything is restored', function () {
    $backup = nxBackupCrafted(nxBackupKey(), ['database.sql' => "-- the dump\nSELECT 1;\n"]);

    $path = (string) $backup->path;
    $bytes = (string) Storage::disk('backups')->get($path);
    $offset = 39 + 5;
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
    Storage::disk('backups')->put($path, $bytes);

    expect(fn () => app(RestoreBackup::class)($backup, 'nexas_restore_altered'))
        ->toThrow(BackupRefusedException::class, 'does not authenticate');

    expect(nxBackupDatabaseExists('nexas_restore_altered'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Restoring — the drill
|--------------------------------------------------------------------------
*/

it('restores a backup into a scratch database, compares it and drops it again', function () {
    nxBackupRequireMysqlTools();

    Storage::disk('attachments')->put('1/01J0/scan.pdf', '%PDF-1.4 fake invoice');

    $this->artisan('backups:run')->assertSuccessful();

    $backup = Backup::query()->sole();
    $target = 'nexas_restore_suite';

    $report = app(RestoreBackup::class)($backup, $target);

    expect($report['target'])->toBe($target)
        ->and($report['tables']['matching'])->toBeTrue()
        ->and($report['tables']['restored'])->toBeGreaterThan(0)
        ->and($report['rows']['matching'])->toBeTrue()
        ->and($report['rows']['mismatches'])->toBe([])
        ->and($report['trial_balance']['matching'])->toBeTrue()
        // Triggers travelled with the dump: the restored database would still
        // refuse an unbalanced journal, which no row count would ever notice.
        ->and($report['triggers']['matching'])->toBeTrue()
        ->and($report['triggers']['restored'])->toBeGreaterThan(0)
        ->and($report['triggers']['missing'])->toBe([])
        // The document count proves the files travelled with the database.
        ->and($report['documents']['matching'])->toBeTrue()
        ->and($report['documents']['in_backup'])->toBe(1);

    // The scratch database is dropped on the way out, whatever happened.
    expect(nxBackupDatabaseExists($target))->toBeFalse();
});

it('reports that there is nothing to restore', function () {
    $this->artisan('backups:restore')
        ->assertFailed()
        ->expectsOutputToContain('there is no restorable backup');
});

it('refuses a wrong key on the console command', function () {
    nxBackupCrafted(nxBackupKey(), ['database.sql' => "-- the dump\nSELECT 1;\n"]);

    $this->artisan('backups:restore', ['--key' => nxBackupKey(99)])
        ->assertFailed()
        ->expectsOutputToContain('would not open');
});

/*
|--------------------------------------------------------------------------
| Where the files live
|--------------------------------------------------------------------------
*/

it('keeps the backup disk outside public and unserved', function () {
    $root = (string) config('filesystems.disks.backups.root');

    expect($root)->toStartWith(storage_path('app/private'))
        ->and(str_starts_with($root, public_path()))->toBeFalse()
        ->and((bool) config('filesystems.disks.backups.serve'))->toBeFalse()
        ->and((int) config('nexas.backups.retention_days'))->toBeGreaterThan(0)
        ->and((int) config('nexas.backups.keep'))->toBeGreaterThan(0);
});

it('schedules the backup after the night\'s verifications', function () {
    // Last of the night's work on purpose: the dump holds the evening's ledger
    // and audit state, so a backup taken before the verifications would be a
    // copy of books that had not been checked yet.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => is_string($event->command) && str_contains($event->command, 'backups:run'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 3 * * *');
});
