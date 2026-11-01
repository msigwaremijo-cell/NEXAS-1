<?php

namespace App\Domain\Importing\Services;

use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\ImportingException;

/**
 * Reads a migration CSV into columns and rows.
 *
 * The same reader the bank statement import uses, for the same reasons, and it
 * stays deliberately strict about the shape of the file while saying nothing
 * about its meaning: it does not know which column holds a code, and it does
 * not convert a single value. Everything that decides what a value means
 * belongs to the importer, which reads it under a mapping the caller named.
 *
 * The separator is detected rather than demanded, from whichever of comma,
 * semicolon, tab and pipe occurs most often in the header line, because a
 * mapping is built against a preview of the file anyway and asking a person to
 * also confirm the file's separator would be asking them for something they
 * should not have to know.
 *
 * A row carrying more values than the header names columns is refused. That is
 * the signature of a separator inside an unquoted value, and reading such a row
 * as it stands moves every later value one column across, so a description ends
 * up in an amount. A row carrying fewer is padded, because a trailing empty
 * column is common and harmless.
 */
final class CsvImportReader
{
    /**
     * The most rows one import may carry. A migration export of a chart of
     * accounts or a customer list is a few thousand lines at most; a file past
     * this is not a migration file, and an import that ties up a request for
     * minutes is worse than a refusal that asks for it to be split.
     */
    public const MAX_ROWS = 5000;

    /**
     * @var list<string>
     */
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function read(string $absolutePath): ImportTable
    {
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw ImportingException::uploadNotStored();
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
                    throw ImportingException::tooManyRows(self::MAX_ROWS);
                }

                $rows[] = $this->keyed($headers, $values, $lineNumber);
                $lines[] = $lineNumber;
            }
        } finally {
            fclose($handle);
        }

        if ($headers === []) {
            throw ImportingException::emptyFile();
        }

        if ($rows === []) {
            throw ImportingException::noDataRows();
        }

        return new ImportTable($headers, $rows, $lines, $delimiter);
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
        if ($values === [] || $values === ['']) {
            throw ImportingException::emptyFile();
        }

        // A file exported on Windows or by a spreadsheet tool may open with a
        // byte order mark. Left in place it becomes part of the first heading,
        // which would then not match the name a person reads off the preview.
        $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];

        $seen = [];

        foreach ($values as $header) {
            if ($header === '') {
                throw ImportingException::unnamedColumn($values);
            }

            if (in_array($header, $seen, true)) {
                throw ImportingException::duplicateColumn($header, $values);
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

            if (array_filter($extra, static fn (string $value): bool => $value !== '') !== []) {
                throw ImportingException::raggedRow($lineNumber, count($headers), count($values));
            }

            $values = array_slice($values, 0, count($headers));
        }

        $values = array_pad($values, count($headers), '');

        return array_combine($headers, $values);
    }

    /**
     * fgetcsv() answers a blank line with [null], and a line of separators with
     * a list of empty strings. Both are skipped: an export usually ends with
     * one, and neither is a row.
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
            throw ImportingException::emptyFile();
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
