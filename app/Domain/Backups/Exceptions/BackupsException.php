<?php

namespace App\Domain\Backups\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * Base type for everything the backup domain refuses or cannot do.
 *
 * These never reach a browser: backups are taken and restored from the console
 * and from the scheduler, so there is no HTTP status to choose here. They are
 * still typed rather than thrown as bare RuntimeExceptions, because a command
 * that fails has to say which of these it was to a person reading a log at two
 * in the morning.
 */
class BackupsException extends AccountingException
{
    /**
     * No key is configured, so there is nothing to encrypt with.
     *
     * A backup holds every journal, every party and every filed document. The
     * only thing standing between that file and anybody who can read the disk
     * is this key, so a missing one is a refusal to run rather than a reason
     * to write the books out in the clear.
     */
    public static function keyMissing(): self
    {
        return new self(
            'No backup key is configured (NEXAS_BACKUP_KEY), so nothing was written. '
            .'A backup carries every company\'s books and must not be written in the clear. '
            .'Generate one with: php -r "echo \'base64:\'.base64_encode(random_bytes(32));" '
            .'— and keep a second copy of it somewhere other than this server.'
        );
    }

    /**
     * A key that is there but is not a 32-byte secret.
     *
     * Failing now rather than at the first restore: a backup taken under a key
     * that was quietly truncated is a backup nobody can open.
     */
    public static function keyMalformed(int $bytes): self
    {
        return new self(sprintf(
            'The backup key decodes to %d bytes; a key of 32 bytes is required. '
            .'Generate one with: php -r "echo \'base64:\'.base64_encode(random_bytes(32));"',
            $bytes
        ));
    }

    /**
     * Every check passed and the store still would not take the file.
     */
    public static function notStored(string $name): self
    {
        return new self(sprintf(
            'The backup "%s" could not be written to the backup disk, so the night has no backup.',
            $name
        ));
    }

    /**
     * The MySQL client a dump or a restore needs is not on this machine.
     */
    public static function binaryMissing(string $binary, string $purpose): self
    {
        return new self(sprintf(
            'The MySQL client "%s", which %s, was not found. Install the MySQL client tools, '
            .'put them on PATH, or name them in full with NEXAS_MYSQLDUMP_BINARY and NEXAS_MYSQL_BINARY.',
            $binary,
            $purpose
        ));
    }

    /**
     * The client ran and refused the job.
     */
    public static function commandFailed(string $purpose, int $exitCode, string $output): self
    {
        $detail = trim($output);

        return new self(sprintf(
            '%s failed (exit code %d).%s',
            $purpose,
            $exitCode,
            $detail === '' ? '' : ' The client said: '.$detail
        ));
    }

    /**
     * The application is not reading its books from MySQL.
     *
     * The dump and the restore are mysqldump and mysql, so there is nothing to
     * hand them: naming the connection says which one to change rather than
     * leaving a command that fails with a client's own complaint about an
     * argument it never got.
     */
    public static function unsupportedConnection(string $name): self
    {
        return new self(sprintf(
            'Backups dump and restore through the MySQL client tools, but the application is connected on "%s" '
            .'and that is not a MySQL connection. Nothing was written.',
            $name
        ));
    }

    /**
     * A database or collation name that is not of the shape these commands are
     * willing to interpolate into SQL.
     *
     * The names this build passes are its own — the configured database, or a
     * scratch name it generated — but they end up inside a statement, so they
     * are checked to that shape first rather than trusted.
     */
    public static function unsafeIdentifier(string $name): self
    {
        return new self(sprintf(
            '"%s" is not a name a backup may use for a database or a collation. Names are letters, digits and '
            .'underscores only.',
            $name
        ));
    }

    /**
     * A destination file could not be prepared, so the run had nowhere to write.
     */
    public static function temporaryMissing(string $purpose): self
    {
        return new self(sprintf(
            '%s could not use a temporary file, so nothing was written.',
            $purpose
        ));
    }
}
