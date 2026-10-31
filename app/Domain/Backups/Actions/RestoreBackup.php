<?php

namespace App\Domain\Backups\Actions;

use App\Domain\Backups\DTOs\BundlePart;
use App\Domain\Backups\Exceptions\BackupRefusedException;
use App\Domain\Backups\Services\BackupBundle;
use App\Domain\Backups\Services\BackupCipher;
use App\Domain\Backups\Services\MysqlClient;
use App\Models\Backup;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Opens a backup into a scratch database and proves it matches the books it
 * was taken from.
 *
 * This is a drill, not a disaster. Its whole value is that it can be run any
 * night, on the real file, without touching anything: it never writes into the
 * live database, it never puts a restored document back over a live one, and
 * it drops the database it created on the way out whatever happened. A restore
 * procedure that has only ever been read is not a restore procedure.
 *
 * It refuses to run into the live database by name, and only runs into a
 * database named as a scratch one, so a mistyped `--target` cannot overwrite
 * the books the application is trading on. That refusal is the reason this is
 * safe to schedule at all.
 *
 * What it compares is chosen to catch the failures that a plain "did it load"
 * would miss. Table counts prove nothing was lost. Row counts, table by table,
 * prove nothing was silently dropped. The trial balance, summed from the
 * ledger, proves the *numbers* came back and not just the rows — and it is
 * read through the statuses that belong in the ledger, so a set of books that
 * has ever been corrected still agrees. Trigger counts prove the restored
 * database would still refuse an unbalanced journal, which no row comparison
 * would ever notice. And the document count proves the files travelled with
 * the database rather than being left behind.
 *
 * A file that will not authenticate — the wrong key, or a byte changed since
 * it was written — is refused before a scratch database is even created.
 *
 * @return array<string, mixed>
 */
final class RestoreBackup
{
    public function __construct(
        private readonly MysqlClient $mysql,
        private readonly BackupBundle $bundle,
    ) {}

    /**
     * @param  string|null  $key  a key to open the backup with, for showing
     *                            that the wrong one is refused; the configured
     *                            key is used when this is null.
     * @return array<string, mixed>
     */
    public function __invoke(Backup $backup, string $target, ?string $key = null): array
    {
        $live = $this->mysql->liveDatabase();
        $prefix = (string) config('nexas.backups.scratch_database_prefix', 'nexas_restore_');

        if ($target === $live) {
            throw BackupRefusedException::targetIsLive($target, $live);
        }

        // The prefix and the whole name are checked together: a restore only
        // ever writes into a database whose name this build generated, so
        // anything else — a typo, a live name, a name with a quote in it — is
        // refused before it reaches a statement.
        if (preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9_]*$/', $target) !== 1) {
            throw BackupRefusedException::targetNotScratch($target, $prefix);
        }

        if (! $backup->isRestorable()) {
            throw BackupRefusedException::notRestorable(
                (string) ($backup->file_name ?? $backup->ulid),
                'it is not a finished backup with a file still on the backup disk.'
            );
        }

        $disk = Storage::disk((string) $backup->disk);
        $path = (string) $backup->path;

        if (! $disk->exists($path)) {
            throw BackupRefusedException::notRestorable(
                (string) $backup->file_name,
                'the file is no longer on the backup disk.'
            );
        }

        $cipher = $key === null ? BackupCipher::fromConfig() : BackupCipher::fromKeyString($key);

        $scratch = sys_get_temp_dir().'/nexas_restore_'.Str::ulid();
        File::ensureDirectoryExists($scratch);

        $encrypted = $scratch.'/'.($backup->file_name ?? 'backup.nxb');
        $bundle = $scratch.'/bundle';
        $unpacked = $scratch.'/unpacked';

        try {
            $this->fetch($disk, $path, $encrypted);

            // Authenticated first: a wrong key or an altered file stops here,
            // before a scratch database exists to leave behind.
            $cipher->decrypt($encrypted, $bundle);

            $manifest = $this->bundle->extract($bundle, $unpacked);

            $dump = $unpacked.'/database.sql';

            if (! is_file($dump)) {
                throw BackupRefusedException::notRestorable(
                    (string) $backup->file_name,
                    'it does not contain a database dump.'
                );
            }

            // A scratch database is dropped first so the drill always starts
            // from nothing: a leftover from an interrupted run would otherwise
            // make the comparison pass against tables the load never wrote.
            $this->mysql->dropDatabase($target);
            $this->mysql->createDatabase($target);
            $this->mysql->load($target, $dump);

            return $this->compare($backup, $live, $target, $manifest);
        } finally {
            // The scratch database goes whether the drill passed, failed or
            // refused: a half-restored copy of the books is not something to
            // leave sitting on the server. Nothing was ever written anywhere
            // else, so this is the only thing to undo.
            $this->mysql->dropDatabase($target);
            File::deleteDirectory($scratch);
        }
    }

    /**
     * Compare the restored database with the one the backup was taken from.
     *
     * @param  list<BundlePart>  $manifest
     * @return array<string, mixed>
     */
    private function compare(Backup $backup, string $live, string $target, array $manifest): array
    {
        $sourceCounts = $this->mysql->rowCounts($live);
        $restoredCounts = $this->mysql->rowCounts($target);

        $sourceBalance = $this->mysql->trialBalance($live);
        $restoredBalance = $this->mysql->trialBalance($target);

        $sourceTriggers = $this->mysql->triggerNames($live);
        $restoredTriggers = $this->mysql->triggerNames($target);

        $mismatches = [];

        foreach ($sourceCounts as $table => $rows) {
            $restored = $restoredCounts[$table] ?? null;

            if ($restored !== $rows) {
                $mismatches[$table] = ['source' => $rows, 'restored' => $restored];
            }
        }

        $documents = 0;

        foreach ($manifest as $part) {
            if ($part->name !== 'database.sql') {
                $documents++;
            }
        }

        return [
            'backup' => (string) $backup->file_name,
            'target' => $target,
            'compared_with' => $live,
            'tables' => [
                'source' => count($sourceCounts),
                'restored' => count($restoredCounts),
                'matching' => count($sourceCounts) === count($restoredCounts),
            ],
            'rows' => [
                'source' => array_sum($sourceCounts),
                'restored' => array_sum($restoredCounts),
                'matching' => $sourceCounts === $restoredCounts,
                'mismatches' => $mismatches,
            ],
            'trial_balance' => [
                'source' => $this->totals($sourceBalance),
                'restored' => $this->totals($restoredBalance),
                'matching' => $sourceBalance === $restoredBalance,
            ],
            'triggers' => [
                'source' => count($sourceTriggers),
                'restored' => count($restoredTriggers),
                'matching' => $sourceTriggers === $restoredTriggers,
                'missing' => array_values(array_diff($sourceTriggers, $restoredTriggers)),
            ],
            'documents' => [
                'in_backup' => $documents,
                'recorded' => (int) $backup->file_count,
                'matching' => $documents === (int) $backup->file_count,
            ],
        ];
    }

    /**
     * The trial balance boiled down to what a person reads: how much was
     * debited, how much credited, and across how many accounts.
     *
     * Summed with bcmath at the money scale, never with floats: the whole point
     * of the comparison is that the figures agree exactly, and a float would
     * make two different sets of books look equal.
     *
     * @param  list<array{company_id: int, account_id: int, debit: string, credit: string}>  $balance
     * @return array{debit: string, credit: string, accounts: int}
     */
    private function totals(array $balance): array
    {
        $scale = (int) config('nexas.money_scale', 4);
        $debit = '0';
        $credit = '0';

        foreach ($balance as $row) {
            $debit = bcadd($debit, $row['debit'], $scale);
            $credit = bcadd($credit, $row['credit'], $scale);
        }

        return ['debit' => $debit, 'credit' => $credit, 'accounts' => count($balance)];
    }

    /**
     * Bring the encrypted file down to a local scratch path, streamed so a
     * backup larger than memory still restores.
     */
    private function fetch(Filesystem $disk, string $path, string $destination): void
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw BackupRefusedException::notRestorable(basename($path), 'the file could not be read from the backup disk.');
        }

        $out = @fopen($destination, 'wb');

        if ($out === false) {
            fclose($stream);

            throw BackupRefusedException::notRestorable(basename($path), 'the file could not be written to a scratch location.');
        }

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($stream);
            fclose($out);
        }
    }
}
