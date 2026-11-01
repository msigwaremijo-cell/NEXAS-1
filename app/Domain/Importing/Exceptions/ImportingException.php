<?php

namespace App\Domain\Importing\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Importing\DTOs\ImportRowError;

/**
 * Everything a migration file, or the mapping chosen for it, can get wrong that
 * the caller can fix.
 *
 * These are all 422s: the file or the mapping can be corrected and submitted
 * again. A file that collides with one already imported is a different kind of
 * problem and has its own type, because resubmitting it unchanged will never
 * help.
 *
 * The per-row complaints are not thrown from here. They are collected as
 * ImportRowError values so an operator sees every bad row in one report rather
 * than one row per attempt; this type is for a refusal that ends the whole run.
 */
class ImportingException extends AccountingException
{
    public static function emptyFile(): self
    {
        return new self('The import file is empty.');
    }

    public static function noDataRows(): self
    {
        return new self('The import file has a header row but no rows under it.');
    }

    public static function tooManyRows(int $limit): self
    {
        return new self(sprintf(
            'The import file holds more than %s rows. Split it into smaller files and import them one at a time.',
            number_format($limit)
        ));
    }

    /**
     * @param  list<string>  $headers
     */
    public static function duplicateColumn(string $column, array $headers): self
    {
        return new self(sprintf(
            'Two columns of the import file are both named "%s" (the file names: %s), so a mapping that refers to it would be ambiguous. Rename one of them in the file and upload it again.',
            $column,
            self::quoted($headers)
        ));
    }

    /**
     * @param  list<string>  $headers
     */
    public static function unnamedColumn(array $headers): self
    {
        return new self(sprintf(
            'The import file has a column with no name in its header row (the file names: %s). Every column has to be named, because the mapping refers to columns by name.',
            self::quoted($headers)
        ));
    }

    public static function raggedRow(int $line, int $expected, int $actual): self
    {
        return new self(sprintf(
            'Line %d of the import file holds %d values but the header names %d columns. An unquoted separator inside a value would shift the columns, so the file has to be corrected before it can be imported.',
            $line,
            $actual,
            $expected
        ));
    }

    /**
     * @param  list<string>  $headers
     */
    public static function unknownColumn(string $label, string $column, array $headers): self
    {
        return new self(sprintf(
            'The mapping points the %s at a column named "%s", but the file has no such column. The file names: %s.',
            $label,
            $column,
            self::quoted($headers)
        ));
    }

    public static function columnMappedTwice(string $column): self
    {
        return new self(sprintf(
            'The mapping uses the column "%s" for two different things. Each column of the file carries one value.',
            $column
        ));
    }

    public static function missingColumn(string $label): self
    {
        return new self(sprintf(
            'The mapping has not named a column for the %s, and the file cannot be read without one.',
            $label
        ));
    }

    /**
     * @param  list<string>  $fields
     */
    public static function unknownField(string $field, array $fields): self
    {
        return new self(sprintf(
            '"%s" is not something this import reads. The fields it knows are: %s.',
            $field,
            implode(', ', $fields)
        ));
    }

    public static function unknownUpload(string $token): self
    {
        return new self(sprintf(
            'There is no import upload called "%s" for this company. Upload the file again and import the upload it returns.',
            $token
        ));
    }

    public static function uploadNotStored(): self
    {
        return new self('The import file could not be stored, so nothing was imported.');
    }

    public static function missingAsOf(string $dataset): self
    {
        return new self(sprintf(
            'The %s import has to be told the date its figures are as at, and none was given.',
            $dataset
        ));
    }

    /**
     * A commit refused because the file still has something wrong with it.
     *
     * The complaints about the file as a whole are carried into the sentence,
     * because a refusal that said only "the file has errors" would hide the one
     * thing an operator needs: what the complaint was. A stock value the ledger
     * disagrees with, and the two figures it disagrees between, are stated here
     * in the same words the dry run reported them.
     *
     * @param  list<ImportRowError>  $errors
     */
    public static function cannotCommit(array $errors): self
    {
        $count = count($errors);

        $summary = sprintf(
            'The file still has %d row%s to correct, so nothing was written. Run it as a dry run to see the full report, correct the file and upload it again.',
            $count,
            $count === 1 ? '' : 's'
        );

        $reasons = [];

        foreach ($errors as $error) {
            if ($error->line === 0) {
                $reasons[] = $error->message;
            }
        }

        return new self($reasons === [] ? $summary : implode(' ', [...$reasons, $summary]));
    }

    /**
     * @param  list<string>  $headers
     */
    private static function quoted(array $headers): string
    {
        return implode(', ', array_map(
            static fn (string $header): string => '"'.$header.'"',
            $headers
        ));
    }
}
