<?php

namespace App\Domain\Importing\Exceptions;

/**
 * A file that has already been imported into these books.
 *
 * This is a 409 rather than a 422 because the file is not malformed and the
 * mapping is not wrong: the request collides with what is already recorded, so
 * sending it again unchanged will produce the same answer every time. The
 * identity is the file's content hash, scoped to the company and the dataset,
 * so the same spreadsheet imported as customers and later as suppliers is two
 * different imports and only the exact repeat is refused.
 *
 * Nothing is written when this is thrown. A half-imported file is worse than a
 * refused one, because the reader cannot tell from the row count which half of
 * it landed.
 */
final class DuplicateImportException extends ImportingException
{
    public static function alreadyImported(string $dataset, string $fileName, int $rows): self
    {
        return new self(sprintf(
            'This file has already been imported as %s: "%s", %d row(s), on an earlier run. The same file is imported once, so nothing was written. '
            .'If the file has been corrected, change a row so its contents differ and upload it again.',
            $dataset,
            $fileName === '' ? 'unnamed file' : $fileName,
            $rows
        ));
    }

    public static function raced(string $dataset): self
    {
        return new self(sprintf(
            'Another import of the same file reached these books first, so nothing was written here. Reload the %s list to see what it imported.',
            $dataset
        ));
    }
}
