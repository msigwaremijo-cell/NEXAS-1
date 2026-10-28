<?php

namespace App\Domain\Banking\Exceptions;

use App\Domain\Banking\Enums\StatementDateFormat;

/**
 * Everything that can be wrong with a statement file or with the mapping the
 * caller chose for it.
 *
 * These are all 422s, because the caller can correct the file or the mapping
 * and submit it again. A file that collides with what is already imported is a
 * different kind of problem and has its own type: resubmitting it unchanged
 * will never help.
 */
class BankStatementException extends BankingException
{
    public static function emptyFile(): self
    {
        return new self('The statement file is empty.');
    }

    public static function noDataRows(): self
    {
        return new self('The statement file has a header row but no movements under it.');
    }

    public static function tooManyRows(int $limit): self
    {
        return new self(sprintf(
            'The statement file holds more than %s movements. Split it into smaller files and import them one at a time.',
            number_format($limit)
        ));
    }

    public static function duplicateColumn(string $column, array $headers): self
    {
        return new self(sprintf(
            'Two columns of the statement are both named "%s" (the file names: %s), so a mapping that refers to it would be ambiguous. Rename one of them in the file and upload it again.',
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
            'The statement has a column with no name in its header row (the file names: %s). Every column has to be named, because the mapping refers to columns by name.',
            self::quoted($headers)
        ));
    }

    /**
     * A row carrying more values than the header names columns. That means a
     * separator inside a value that was not quoted, which shifts every later
     * value one column across: read as it stands, the description would be
     * imported as the amount. Refused rather than padded, because the error is
     * silent and lands on the money.
     */
    public static function raggedRow(int $line, int $expected, int $actual): self
    {
        return new self(sprintf(
            'Line %d of the statement holds %d values but the header names %d columns. An unquoted separator inside a value would shift the columns, so the file has to be corrected before it can be imported.',
            $line,
            $actual,
            $expected
        ));
    }

    /**
     * @param  list<string>  $headers
     */
    public static function unknownColumn(string $role, string $column, array $headers): self
    {
        return new self(sprintf(
            'The mapping points the %s at a column named "%s", but the file has no such column. The file names: %s.',
            $role,
            $column,
            self::quoted($headers)
        ));
    }

    public static function columnMappedTwice(string $column): self
    {
        return new self(sprintf(
            'The mapping uses the column "%s" for two different things. Each column of the statement carries one value.',
            $column
        ));
    }

    public static function amountMappingAmbiguous(): self
    {
        return new self('Choose either one signed amount column or the money in and money out columns, not both: a movement described twice cannot be read twice.');
    }

    public static function amountMappingMissing(): self
    {
        return new self('The mapping has to name either one signed amount column, or at least one of the money in and money out columns, or there is no way to tell what each line moved.');
    }

    public static function missingDate(string $column, int $line): self
    {
        return new self(sprintf(
            'Line %d of the statement has nothing in its %s column, so there is no date to place the movement on.',
            $line,
            $column
        ));
    }

    public static function unreadableDate(string $column, int $line, string $value, StatementDateFormat $format): self
    {
        return new self(sprintf(
            'Line %d of the statement has "%s" in its %s column, which is not a date written as %s. Choose the layout the file actually uses and import it again.',
            $line,
            $value,
            $column,
            $format->label()
        ));
    }

    public static function unreadableAmount(string $role, string $column, int $line, string $value): self
    {
        return new self(sprintf(
            'Line %d of the statement has "%s" in its %s column (mapped to %s), which is not an amount.',
            $line,
            $value,
            $column,
            $role
        ));
    }

    /**
     * A figure written the way a bank in a comma-decimal country writes it,
     * e.g. 1.234,56. Reading the commas as grouping would turn it into 1.23456
     * and move the decimal point three places, so the line is refused rather
     * than guessed at: a reconciliation built on a figure a thousand times too
     * small is worse than one that did not run.
     */
    public static function commaDecimalAmount(string $column, int $line, string $value): self
    {
        return new self(sprintf(
            'Line %d of the statement has "%s" in its %s column, which looks like a figure written with a comma as the decimal separator. This importer reads a full stop as the decimal separator, so convert the column before importing.',
            $line,
            $value,
            $column
        ));
    }

    /**
     * The books hold DECIMAL(19,4), so an amount is at most 15 digits before
     * the decimal point. Refusing it here rather than letting MySQL reject the
     * insert keeps the whole import in one piece and says which line is wrong.
     */
    public static function amountOutOfRange(string $role, string $column, int $line, string $value): self
    {
        return new self(sprintf(
            'Line %d of the statement has "%s" in its %s column (mapped to %s), which is larger than this company can record: an amount may have at most 15 digits before the decimal point.',
            $line,
            $value,
            $column,
            $role
        ));
    }

    public static function negativeInDirectionColumn(string $role, string $column, int $line, string $value): self
    {
        return new self(sprintf(
            'Line %d of the statement has "%s" in the %s column, which is mapped to %s. A money in or money out column is read as a positive figure; if the file carries one signed column instead, map it as the amount.',
            $line,
            $value,
            $column,
            $role
        ));
    }

    public static function noMovement(int $line): self
    {
        return new self(sprintf(
            'Line %d of the statement moves no money, so there is nothing for it to reconcile against.',
            $line
        ));
    }

    public static function bothSidesFilled(int $line): self
    {
        return new self(sprintf(
            'Line %d of the statement fills in both the money in and the money out column. One line of a statement is one movement, so it cannot be both.',
            $line
        ));
    }

    public static function textTooLong(string $role, int $line, int $limit): self
    {
        return new self(sprintf(
            'Line %d of the statement has a %s longer than %d characters, which is more than the books hold.',
            $line,
            $role,
            $limit
        ));
    }

    public static function unknownUpload(string $token): self
    {
        return new self(sprintf(
            'There is no statement upload called "%s" for this company. Upload the file again and import the upload it returns.',
            $token
        ));
    }

    public static function uploadNotStored(): self
    {
        return new self('The statement file could not be stored, so nothing was imported.');
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
