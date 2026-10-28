<?php

namespace App\Domain\Banking\DTOs;

/**
 * A statement file read into memory, with the header taken off.
 *
 * Rows are keyed by their column heading rather than by position, so a mapping
 * that names a column reads the same value whether the file puts it first or
 * last. The physical line each row came from is kept beside it: a refusal that
 * says "line 47" is something a person can act on in a spreadsheet, whereas
 * "movement 46" is not, and blank lines in the file mean the two numbers do not
 * agree.
 *
 * Headings are treated as fixed text: they are trimmed of surrounding
 * whitespace but otherwise matched exactly, because a mapping built against a
 * preview has to name the column the file actually carries.
 */
final readonly class StatementTable
{
    /**
     * @param  list<string>  $headers  column headings, in file order
     * @param  list<array<string, string>>  $rows  values keyed by heading
     * @param  list<int>  $lines  physical file line of each row
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public array $lines,
        public string $delimiter,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * The first few rows, each with the file line it came from, so the mapping
     * step can be built against what is really in the file.
     *
     * @return list<array{line: int, values: array<string, string>}>
     */
    public function preview(int $limit): array
    {
        $preview = [];

        foreach (array_slice($this->rows, 0, $limit, true) as $index => $values) {
            $preview[] = ['line' => $this->lines[$index], 'values' => $values];
        }

        return $preview;
    }
}
