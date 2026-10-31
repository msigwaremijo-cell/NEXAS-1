<?php

namespace App\Domain\Backups\Exceptions;

/**
 * A backup that will not be opened, or a target it will not be opened into.
 *
 * These are the refusals that matter. The file is either not what it claims to
 * be — the wrong key, a byte changed, a part that no longer hashes to what the
 * run recorded — or the restore is being pointed somewhere it must never
 * write, such as the database the application is reading its own books from.
 *
 * None of them is repairable by trying again with the same inputs, which is
 * why they say what was found rather than what to change: the answer is a
 * different key or a different file, and only the person holding those can
 * supply it.
 */
class BackupRefusedException extends BackupsException
{
    /**
     * The file did not authenticate under this key.
     *
     * One message covers both a wrong key and an altered file, because the two
     * are indistinguishable at the point of refusal — a single flipped byte
     * and a different key both fail the same check — and pretending to tell
     * them apart would be a guess dressed up as a diagnosis.
     */
    public static function willNotOpen(string $name): self
    {
        return new self(sprintf(
            'The backup "%s" would not open: it does not authenticate under the configured key. '
            .'Either the key is not the one it was written with, or the file has been altered. '
            .'Nothing was restored.',
            $name
        ));
    }

    /**
     * The file is open but is not a backup this build wrote.
     */
    public static function notABackup(string $name): self
    {
        return new self(sprintf(
            'The file "%s" is not a Nexas backup, or was written by a version of the format this build '
            .'does not read. Nothing was restored.',
            $name
        ));
    }

    /**
     * A part of the bundle does not match the checksum recorded for it when the
     * backup was taken.
     */
    public static function partAltered(string $part, string $expected, string $found): self
    {
        return new self(sprintf(
            'The part "%s" inside the backup does not match the checksum recorded for it '
            .'(recorded %s, found %s). The file has been altered. Nothing was restored.',
            $part,
            $expected,
            $found
        ));
    }

    /**
     * A part is the wrong length, so the file was cut short.
     */
    public static function partTruncated(string $part, int $expected, int $found): self
    {
        return new self(sprintf(
            'The part "%s" inside the backup is %d bytes where %d were recorded, so the file is '
            .'incomplete. Nothing was restored.',
            $part,
            $found,
            $expected
        ));
    }

    /**
     * A part is named in a way that would write outside the directory it is
     * being unpacked into.
     */
    public static function partUnsafe(string $part): self
    {
        return new self(sprintf(
            'The part "%s" inside the backup is named in a way that would write outside the folder it '
            .'was unpacked into, so the file is not trustworthy. Nothing was restored.',
            $part
        ));
    }

    /**
     * A restore pointed at the database the application is using.
     *
     * This is the one refusal that exists to protect working books rather than
     * a file, so it names both databases: a person who typed the wrong name
     * should be able to see at a glance which name they should have typed.
     */
    public static function targetIsLive(string $target, string $live): self
    {
        return new self(sprintf(
            'Refusing to restore into "%s": that is the database this application is reading its own '
            .'books from. Restore into a scratch database instead — for example %s%s.',
            $target,
            config('nexas.backups.scratch_database_prefix', 'nexas_restore_'),
            'drill'
        ));
    }

    /**
     * A restore pointed at a database that is not named as a scratch one.
     */
    public static function targetNotScratch(string $target, string $prefix): self
    {
        return new self(sprintf(
            'Refusing to restore into "%s": a restore only ever writes into a scratch database, and '
            .'those are named with the prefix "%s".',
            $target,
            $prefix
        ));
    }

    /**
     * The row names a file that is not a backup that can be restored.
     */
    public static function notRestorable(string $reference, string $reason): self
    {
        return new self(sprintf(
            'The backup "%s" cannot be restored: %s',
            $reference,
            $reason
        ));
    }
}
