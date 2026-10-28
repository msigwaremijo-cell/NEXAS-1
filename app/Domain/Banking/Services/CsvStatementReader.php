<?php

namespace App\Domain\Banking\Services;

use App\Domain\Banking\DTOs\StatementTable;
use App\Domain\Banking\Exceptions\BankStatementException;

/**
 * Reads a CSV statement file into columns and rows.
 *
 * The reader is deliberately strict about the shape of the file and silent
 * about its meaning: it does not know which column holds the date, and it does
 * not convert a single value. Everything that decides what a value means
 * belongs to the mapping step, which the caller names explicitly.
 *
 * Two decisions are worth stating.
 *
 * The separator is detected rather than demanded, from whichever of comma,
 * semicolon, tab and pipe occurs most often in the header line. A mapping is
 * built against a preview of the file anyway, so asking a person to also
 * confirm what the file's own separator is would be asking them for something
 * they should not have to know - and the header line is the one line that
 * cannot be confused by a separator inside a narration.
 *
 * A row carrying more values than the header names columns is refused. That is
 * the signature of a separator inside an unquoted value, and reading such a row
 * as it stands moves every later value one column across, so the narration ends
 * up in the amount. A row carrying fewer is padded, because a trailing empty
 * column is common and harmless.
 */
final class CsvStatementReader
{
    /**
     * The most movements one import may carry. A year of a busy current account
     * is a few thousand lines; a file past this is not a bank statement, and an
     * import that ties up a request for minutes is worse than a refusal that
     * asks for it to be split.
     */
    public const MAX_ROWS = 5000;

    /**
     * @var list<string>
     */
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function read(string $absolutePath): StatementTable
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw BankStatementException::uploadNotStored();
        }

        try {
            $delimiter = $this->detectDelimiter($handle);

            $headers = [];
            $rows = [];
            $lines = [];
            $lineNumber = 0;

            while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($this->isBlankLine($values)) {
                    continue;
                }

                /** @var list<string> $values */
                $values = array_map(static fn ($value): string => trim((string) $value), $values);

                if ($headers === []) {
                    $headers = $this->readHeaders($values);

                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw BankStatementException::tooManyRows(self::MAX_ROWS);
                }

                $rows[] = $this->keyed($headers, $values, $lineNumber);
                $lines[] = $lineNumber;
            }
        } finally {
            fclose($handle);
        }

        if ($headers === []) {
            throw BankStatementException::emptyFile();
        }

        if ($rows === []) {
            throw BankStatementException::noDataRows();
        }

        return new StatementTable($headers, $rows, $lines, $delimiter);
    }

    /**
     * The header line, checked for the one thing that makes a mapping
     * impossible: two columns with the same name.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function readHeaders(array $values): array
    {
        // A file exported on Windows or by a bank's Excel add-in may open with a
        // byte order mark. Left in place it becomes part of the first heading,
        // which would then not match the name a person reads off the preview.
        $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];

        if ($values === [] || $values === ['']) {
            throw BankStatementException::emptyFile();
        }

        $seen = [];

        foreach ($values as $header) {
            if ($header === '') {
                throw BankStatementException::unnamedColumn($values);
            }

            if (in_array($header, $seen, true)) {
                throw BankStatementException::duplicateColumn($header, $values);
            }

            $seen[] = $header;
        }

        return $values;
    }

    /**
     * One row's values, keyed by the heading they sit under.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function keyed(array $headers, array $values, int $lineNumber): array
    {
        if (count($values) > count($headers)) {
            $extra = array_slice($values, count($headers));

            // Trailing separators produce empty extra cells, which are the one
            // harmless case; a real value in a column the header does not name
            // means the row is shifted and every value on it is in doubt.
            if (array_filter($extra, static fn (string $value): bool => $value !== '') !== []) {
                throw BankStatementException::raggedRow($lineNumber, count($headers), count($values));
            }

            $values = array_slice($values, 0, count($headers));
        }

        $values = array_pad($values, count($headers), '');

        return array_combine($headers, $values);
    }

    /**
     * fgetcsv() answers a blank line with [null], and a line of separators with
     * a list of empty strings. Both are skipped: a bank export usually ends with
     * one, and neither is a movement.
     *
     * @param  array<int, string|null>  $values
     */
    private function isBlankLine(array $values): bool
    {
        return array_filter($values, static fn ($value): bool => trim((string) $value) !== '') === [];
    }

    /**
     * @param  resource  $handle
     */
    private function detectDelimiter($handle): string
    {
        $firstLine = (string) fgets($handle);

        rewind($handle);

        if (trim($firstLine) === '') {
            throw BankStatementException::emptyFile();
        }

        $best = self::DELIMITERS[0];
        $bestCount = 0;

        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($firstLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
