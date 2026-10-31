<?php

namespace App\Console\Commands;

use App\Domain\Backups\Actions\CreateBackup;
use App\Domain\Backups\Actions\PruneBackups;
use App\Models\Backup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes the nightly backup, and then applies the retention policy to the
 * files already held.
 *
 * The two belong in one run. A retention policy that is not attached to the
 * thing that creates the files is a policy somebody has to remember, and the
 * night it is forgotten is the night the disk fills and the backup fails — so
 * the file is written first and pruned immediately after, in the same run, and
 * the run reports both.
 *
 * A failure to write is reported and nothing is pruned: if the night produced
 * no backup, the last thing this should do is take older ones away. The exit
 * code is what the scheduler reports on.
 */
class RunBackups extends Command
{
    protected $signature = 'backups:run';

    protected $description = 'Write one encrypted backup of the database and the filed documents, then prune those past retention';

    public function handle(CreateBackup $create, PruneBackups $prune): int
    {
        try {
            $backup = $create();
        } catch (Throwable $failure) {
            // The row, if one was written, already says what went wrong and is
            // marked failed. Nothing is pruned.
            $this->error('FAIL: '.$failure->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'OK: backup %s written (%s bytes, %d table(s), %s row(s), %d document(s)).',
            $backup->file_name,
            number_format((int) $backup->size_bytes),
            (int) $backup->table_count,
            number_format((int) $backup->row_count),
            (int) $backup->file_count,
        ));

        $pruned = $prune();

        $this->info(sprintf(
            'OK: retention applied, %d file(s) pruned, %d still held.',
            $pruned,
            Backup::query()->restorable()->count(),
        ));

        return self::SUCCESS;
    }
}
