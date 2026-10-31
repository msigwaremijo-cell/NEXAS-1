<?php

namespace App\Jobs;

use App\Domain\Reporting\Enums\ExportFormat;
use App\Domain\Reporting\Exports\CsvWriter;
use App\Domain\Reporting\Exports\PdfWriter;
use App\Domain\Reporting\Exports\ReportExportRegistry;
use App\Models\Export;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Render one requested export to a file on a disk outside public.
 *
 * This is why the export surface needs a worker. Reading a year of the ledger
 * is the expensive part of a heavy report, and doing it inline would hold a
 * request open for as long as the query takes. Instead the request writes an
 * exports row and hands back something to poll, and this job does the reading
 * off the request's back: `php artisan queue:work` (the `database` queue by
 * default) is what actually produces the file, and without a worker running the
 * record sits at queued and no file is ever written.
 *
 * The job catches everything it raises, records the message on the row, and
 * rethrows so the queue's own failed-jobs handling still sees it. A failure
 * therefore has two records — a clear error on the export, and the stack trace
 * the queue keeps — rather than a row that says running for ever.
 */
class GenerateExport implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $exportId,
    ) {}

    public function handle(ReportExportRegistry $registry, CsvWriter $csv, PdfWriter $pdf): void
    {
        $export = Export::query()->find($this->exportId);

        // Pruned between the request and the worker's turn: there is nothing to
        // render and nothing to report, because the row that would have held
        // the answer is gone.
        if ($export === null) {
            return;
        }

        $export->markRunning();

        try {
            $definition = $registry->resolve($export->type);

            $document = ($definition->build)((int) $export->company_id, $export->parameters ?? []);

            $contents = $export->format === ExportFormat::Pdf
                ? $pdf->render($document)
                : $csv->render($document);

            $path = $export->path();

            Storage::disk($export->disk)->put($path, $contents);

            $export->markDone($path, $export->fileName(), strlen($contents));
        } catch (Throwable $exception) {
            $export->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    /**
     * The queue calls this when the job has used up its tries without handle()
     * having recorded the failure itself — a worker killed mid-render, a
     * timeout the process never came back from. The row must still end at
     * failed rather than sit on running for ever, so the state is set here
     * again, defensively, from whatever the queue passed.
     */
    public function failed(?Throwable $exception): void
    {
        $export = Export::query()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->markFailed($exception?->getMessage() ?? 'The export job failed before it could record why.');
    }
}
