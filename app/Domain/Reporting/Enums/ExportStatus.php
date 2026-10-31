<?php

namespace App\Domain\Reporting\Enums;

/**
 * Where a requested export has got to.
 *
 * An export is produced by a queued job, so between the request and the file
 * there is a stretch of time in which the answer is neither yes nor no. Rather
 * than make the caller poll a blank, the record says which of these it is:
 *
 *   - Queued   the row exists and the job is waiting for a worker;
 *   - Running  a worker has picked it up and is building the file;
 *   - Done     the file is written and downloadable while it has not expired;
 *   - Failed   the job raised, and the error beside it says what went wrong.
 *
 * Done and Failed are terminal. A failed export is not retried in place: the
 * caller asks for a fresh one, which is a new row, because a file that failed
 * to build once is a request to reissue rather than a row to edit.
 */
enum ExportStatus: string
{
    case Queued = 'queued';

    case Running = 'running';

    case Done = 'done';

    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }

    /**
     * A finished export whose file exists and may still be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this === self::Done;
    }
}
