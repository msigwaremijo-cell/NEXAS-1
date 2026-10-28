<?php

namespace App\Domain\Banking\Exceptions;

/**
 * A statement that repeats movements the books already hold.
 *
 * This is a 409 rather than a 422 because the file is not malformed and the
 * mapping is not wrong: the request collides with what is already recorded, so
 * sending it again unchanged will produce the same answer every time.
 *
 * Nothing is imported when this is thrown. A part-imported statement is worse
 * than a refused one, because the reader cannot tell from the line count alone
 * which half of the file landed.
 */
final class DuplicateStatementException extends BankStatementException
{
    /**
     * @param  list<int>  $lines  the file lines that repeat one another
     */
    public static function withinFile(array $lines): self
    {
        return new self(sprintf(
            'The statement lists the same movement more than once (file lines %s). A statement line is one movement, so the file is either duplicated or was concatenated with itself.',
            self::list($lines)
        ));
    }

    /**
     * @param  list<int>  $lines  the file lines already present in the books
     */
    public static function alreadyImported(array $lines): self
    {
        return new self(sprintf(
            'The statement holds %d movement(s) this account has already imported (file lines %s). Nothing was imported and the lines already in the books are untouched; import only the part of the file that is new.',
            count($lines),
            self::list($lines)
        ));
    }

    public static function raced(): self
    {
        return new self('Another import of the same statement reached this account first, so nothing was imported here. Reload the statement to see the lines it wrote.');
    }

    /**
     * @param  list<int>  $lines
     */
    private static function list(array $lines): string
    {
        $shown = array_slice($lines, 0, 10);

        $text = implode(', ', $shown);

        return count($lines) > count($shown)
            ? $text.', and '.(count($lines) - count($shown)).' more'
            : $text;
    }
}
