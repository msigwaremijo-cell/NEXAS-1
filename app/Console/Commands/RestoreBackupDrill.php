<?php

namespace App\Console\Commands;

use App\Domain\Backups\Actions\RestoreBackup;
use App\Models\Backup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restores a backup into a scratch database and reports whether the books came
 * back whole.
 *
 * The database it restores into is always a scratch one, and the drill drops
 * it again on the way out. Nothing about the application's own database or its
 * stored documents is touched. That is the point: this can be run on a real
 * backup on a live server without any risk to what is running.
 *
 * The comparison is against the database the application is reading from — the
 * source the backup was taken from — and covers tables, row counts table by
 * table, the trial balance, the triggers and the document count. Any check
 * that does not match makes the command exit non-zero, so an unattended drill
 * that found the backups were not restorable leaves a trace rather than a line
 * of output that scrolled away.
 */
class RestoreBackupDrill extends Command
{
    protected $signature = 'backups:restore
        {backup? : the ULID or file name of the backup; the newest restorable backup is used when omitted}
        {--target= : the scratch database to restore into; one is generated when omitted}
        {--key= : open the backup with this key instead of the configured one, to show a wrong key is refused}';

    protected $description = 'Restore a backup into a scratch database, compare it with the source, and drop it again';

    public function handle(RestoreBackup $restore): int
    {
        $backup = $this->backup();

        if ($backup === null) {
            $this->error('FAIL: there is no restorable backup to restore.');

            return self::FAILURE;
        }

        $target = (string) ($this->option('target') ?: config('nexas.backups.scratch_database_prefix', 'nexas_restore_').'drill');

        try {
            $report = $restore($backup, $target, $this->option('key') === null ? null : (string) $this->option('key'));
        } catch (Throwable $refusal) {
            $this->error('FAIL: '.$refusal->getMessage());

            return self::FAILURE;
        }

        $this->report($report);

        return $this->passed($report) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The backup named on the command line, or the newest one held.
     */
    private function backup(): ?Backup
    {
        $reference = $this->argument('backup');

        if ($reference !== null) {
            return Backup::query()
                ->restorable()
                ->where('ulid', (string) $reference)
                ->orWhere('file_name', (string) $reference)
                ->first();
        }

        return Backup::query()
            ->restorable()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function report(array $report): void
    {
        $this->info(sprintf(
            'Restored %s into "%s" and compared it with "%s".',
            $report['backup'],
            $report['target'],
            $report['compared_with'],
        ));

        $this->newLine();

        $this->table(['Check', 'Source', 'Restored', 'Result'], [
            ['Tables', $report['tables']['source'], $report['tables']['restored'], $this->word($report['tables']['matching'])],
            ['Rows', number_format((int) $report['rows']['source']), number_format((int) $report['rows']['restored']), $this->word($report['rows']['matching'])],
            ['Trial balance, debits', $report['trial_balance']['source']['debit'], $report['trial_balance']['restored']['debit'], $this->word($report['trial_balance']['matching'])],
            ['Trial balance, credits', $report['trial_balance']['source']['credit'], $report['trial_balance']['restored']['credit'], $this->word($report['trial_balance']['matching'])],
            ['Trial balance, accounts', $report['trial_balance']['source']['accounts'], $report['trial_balance']['restored']['accounts'], $this->word($report['trial_balance']['matching'])],
            ['Triggers', $report['triggers']['source'], $report['triggers']['restored'], $this->word($report['triggers']['matching'])],
            ['Documents', $report['documents']['in_backup'], $report['documents']['recorded'], $this->word($report['documents']['matching'])],
        ]);

        foreach ($report['rows']['mismatches'] as $table => $counts) {
            $this->error(sprintf(
                '  table %s: source %s row(s), restored %s',
                $table,
                $counts['source'] ?? '(none)',
                $counts['restored'] ?? '(none)',
            ));
        }

        foreach ($report['triggers']['missing'] as $trigger) {
            $this->error(sprintf('  trigger %s is missing from the restored database', $trigger));
        }

        $this->newLine();

        if ($this->passed($report)) {
            $this->info(sprintf('OK: the restored books agree with the source in every check. "%s" has been dropped.', $report['target']));
        } else {
            $this->error(sprintf('FAIL: the restored books do not agree with the source. "%s" has been dropped.', $report['target']));
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function passed(array $report): bool
    {
        return $report['tables']['matching']
            && $report['rows']['matching']
            && $report['trial_balance']['matching']
            && $report['triggers']['matching']
            && $report['documents']['matching'];
    }

    private function word(bool $matching): string
    {
        return $matching ? 'match' : 'DIFFERS';
    }
}
