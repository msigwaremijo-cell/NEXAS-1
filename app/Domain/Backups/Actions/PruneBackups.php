<?php

namespace App\Domain\Backups\Actions;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;

/**
 * Takes backup files off the disk once they are older than the retention
 * window, and answers how many it removed.
 *
 * Two rules govern what goes, and the floor matters more than the window. A
 * file is only a candidate once it is older than `retention_days`. But the
 * newest `keep` files are never candidates at all, whatever their age: the
 * window is a policy about how long copies are worth holding, and the floor is
 * a floor against a mistake — a window shorter than the gap between runs, a
 * clock that moved, a shop that stopped trading for a fortnight — ever leaving
 * nobody any copy at all. A retention policy that can delete the last backup
 * is worse than no policy.
 *
 * The row stays and only the file goes, exactly as with an export: that a copy
 * was taken on this night outlives the copy, and its path is cleared so a
 * later run cannot try to remove the same file twice and so a restore reports
 * the backup as no longer held rather than as ready.
 *
 * The file and the row are updated in that order, so a crash between them
 * leaves a row pointing at a file that is already gone — visible and
 * recoverable — rather than a file no row accounts for, which nothing would
 * ever clean up.
 */
final class PruneBackups
{
    public function __invoke(): int
    {
        $disk = Storage::disk((string) config('nexas.backups.disk'));
        $days = max(1, (int) config('nexas.backups.retention_days', 30));
        $keep = max(1, (int) config('nexas.backups.keep', 7));

        $backups = Backup::query()
            ->restorable()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        $cutoff = now()->subDays($days);
        $pruned = 0;

        // Everything past the first `keep` newest files is a candidate.
        foreach ($backups->slice($keep) as $backup) {
            if ($backup->completed_at !== null && $backup->completed_at->greaterThan($cutoff)) {
                continue;
            }

            if ($backup->path !== null && $disk->exists($backup->path)) {
                $disk->delete($backup->path);
            }

            $backup->markPruned();
            $pruned++;
        }

        return $pruned;
    }
}
