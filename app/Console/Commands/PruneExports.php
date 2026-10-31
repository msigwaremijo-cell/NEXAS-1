<?php

namespace App\Console\Commands;

use App\Models\Export;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Takes rendered report exports off the disk once their retention window has
 * passed.
 *
 * A rendered export is the company's books in a file, so it is written outside
 * public and served only through a signed, expiring link. That link bounds how
 * long the file may be *fetched*; this bounds how long it *exists*. The two
 * are different questions — the link is short so a leaked one is useless, the
 * file lives longer so a report is not lost the moment its first link lapses —
 * and the retention window in config/nexas.php is the answer to the second.
 *
 * The row stays and only the file goes. What was asked for, when, by whom and
 * whether it rendered are a record of the books being read, and that record
 * outlives the file it produced; the row's path is cleared so a later run
 * cannot try to remove the same file twice, and so the download route reports
 * the export as no longer retained rather than as ready.
 *
 * The file and the row are updated in that order, so a crash between them
 * leaves a row pointing at a file that is already gone — recoverable and
 * visible — rather than a file with no row that would never be cleaned up.
 */
class PruneExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete rendered report exports that have passed their retention window';

    public function handle(): int
    {
        $disk = Storage::disk((string) config('nexas.exports.disk'));
        $pruned = 0;
        $missing = 0;

        Export::query()
            ->expired()
            ->whereNotNull('path')
            ->orderBy('id')
            ->chunkById(100, function ($exports) use ($disk, &$pruned, &$missing): void {
                foreach ($exports as $export) {
                    $path = (string) $export->path;

                    if ($disk->exists($path)) {
                        $disk->delete($path);
                        $pruned++;
                    } else {
                        // Already gone — removed by hand, or by a run that was
                        // interrupted after the delete. The row is still
                        // cleared, so this is a bookkeeping correction rather
                        // than a second deletion.
                        $missing++;
                    }

                    $export->forceFill([
                        'path' => null,
                        'file_name' => null,
                        'size_bytes' => null,
                    ])->save();
                }
            });

        $this->info(sprintf(
            'Pruned %d export file(s)%s.',
            $pruned,
            $missing > 0 ? sprintf(' (%d were already gone)', $missing) : '',
        ));

        return self::SUCCESS;
    }
}
