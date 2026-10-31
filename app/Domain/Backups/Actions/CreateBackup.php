<?php

namespace App\Domain\Backups\Actions;

use App\Domain\Backups\Enums\BackupStatus;
use App\Domain\Backups\Exceptions\BackupsException;
use App\Domain\Backups\Services\BackupBundle;
use App\Domain\Backups\Services\BackupCipher;
use App\Domain\Backups\Services\MysqlClient;
use App\Models\Backup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Takes one backup: the database, and the documents filed against it, in a
 * single encrypted file.
 *
 * The order of the steps is the whole design. A row is written first, so a run
 * that dies has left a record of having started. The database is dumped, the
 * documents are gathered beside it, and the two are packed into one bundle —
 * all in a scratch directory that is thrown away at the end whatever happened,
 * so no plaintext copy of the books is ever left lying about. The bundle is
 * then encrypted, and only the encrypted file is written to the backup disk.
 *
 * Two things are deliberately not in here. Exports are not: a rendered report
 * is derivable from the ledger it was rendered from, so archiving it would
 * only make every backup bigger to hold a copy of something that can be made
 * again. And the key is checked before the row is written rather than after:
 * a missing key is a deployment that will fail again tomorrow, and a backups
 * table filling up with failed rows would look like the backups were being
 * attempted when the truth is that nothing was ever going to be written.
 *
 * The four figures recorded on the row — tables, rows, documents, size — are
 * what a restore drill compares against, and they are taken while the copy is
 * being made rather than derived afterwards, so a drill needs nothing but the
 * row and the file.
 */
final class CreateBackup
{
    public function __construct(
        private readonly MysqlClient $mysql,
        private readonly BackupBundle $bundle,
    ) {}

    public function __invoke(): Backup
    {
        // Loudly, before anything is written: a key that is absent or the
        // wrong length means every backup from now on would be unopenable.
        $cipher = BackupCipher::fromConfig();

        $disk = (string) config('nexas.backups.disk');
        $database = $this->mysql->liveDatabase();

        $backup = Backup::create([
            'disk' => $disk,
            'status' => BackupStatus::Running,
            'database_name' => $database,
            'started_at' => now(),
        ]);

        $scratch = sys_get_temp_dir().'/nexas_backup_'.Str::ulid();

        File::ensureDirectoryExists($scratch);

        try {
            $counts = $this->mysql->rowCounts($database);

            $sources = $this->sources($scratch, $database);
            $documents = count($sources) - 1;

            $this->bundle->write($sources, $scratch.'/bundle');

            $fileName = $this->fileName($backup);
            $path = now()->format('Y/m').'/'.$fileName;

            $cipher->encrypt($scratch.'/bundle', $scratch.'/'.$fileName);

            $this->store($disk, $path, $scratch.'/'.$fileName);

            $backup->markCompleted(
                path: $path,
                fileName: $fileName,
                sizeBytes: (int) filesize($scratch.'/'.$fileName),
                checksum: (string) hash_file('sha256', $scratch.'/'.$fileName),
                tableCount: count($counts),
                rowCount: array_sum($counts),
                fileCount: $documents,
            );
        } catch (Throwable $failure) {
            // The row is kept and marked rather than deleted: a night that
            // produced no backup is exactly the thing somebody goes looking
            // for later, and a missing row would look like the run never
            // happened at all.
            $backup->markFailed($failure->getMessage());

            throw $failure;
        } finally {
            File::deleteDirectory($scratch);
        }

        return $backup;
    }

    /**
     * Everything that goes into the bundle: the dump first, then every filed
     * document from each configured store.
     *
     * The part name is the store's name followed by the file's path inside it,
     * which is what lets a restore put each file back where it belongs without
     * having to guess from anything the file itself contains. Exports are not
     * in the configured list on purpose; see the class note.
     *
     * @return array<string, string> part name => absolute source path
     */
    private function sources(string $scratch, string $database): array
    {
        $dump = $scratch.'/database.sql';

        $this->mysql->dump($database, $dump);

        $sources = ['database.sql' => $dump];

        foreach ((array) config('nexas.backups.include', []) as $store) {
            $store = (string) $store;
            $disk = Storage::disk($store);

            foreach ($disk->allFiles() as $relative) {
                $sources[$store.'/'.$relative] = $disk->path($relative);
            }
        }

        return $sources;
    }

    /**
     * Write the encrypted file onto the backup disk, streamed rather than read
     * into memory: a real backup does not fit there.
     */
    private function store(string $disk, string $path, string $source): void
    {
        $stream = @fopen($source, 'rb');

        if ($stream === false) {
            throw BackupsException::notStored(basename($source));
        }

        try {
            Storage::disk($disk)->put($path, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * The name the file is written under: the date and time it was taken, and
     * the row's public identifier so two runs in the same second cannot collide.
     */
    private function fileName(Backup $backup): string
    {
        return sprintf('nexas-%s-%s.nxb', now()->format('Ymd-His'), $backup->ulid);
    }
}
