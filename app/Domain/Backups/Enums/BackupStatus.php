<?php

namespace App\Domain\Backups\Enums;

/**
 * Where a nightly backup run has got to.
 *
 * A backup is written in one go and cannot be half-done in a way that is worth
 * keeping: a file that was cut off mid-write is not a smaller backup, it is a
 * corrupt one. So the record says which of these it is and nothing in between:
 *
 *   - Running    the row exists and the file is being written;
 *   - Completed  the file is written, its checksum recorded, and it may be
 *                restored;
 *   - Failed     the run raised, and the error beside it says what went wrong.
 *
 * Only a Completed backup is ever offered to a restore. A Failed row is kept
 * rather than deleted, because a night that produced no backup is exactly the
 * thing somebody looks for later.
 */
enum BackupStatus: string
{
    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }

    /**
     * A backup whose file exists and may be restored.
     */
    public function isRestorable(): bool
    {
        return $this === self::Completed;
    }
}
