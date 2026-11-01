<?php

namespace App\Domain\Importing\DTOs;

/**
 * A CSV import file read into memory, with the header taken off.
 *
 * The twin of the bank statement reader's table, and the same two decisions
 * are made here for the same reasons: rows are keyed by their column heading so
 * a mapping reads the same value wherever the file puts the column, and the
 * physical line each row came from is kept beside it so a refusal can name a
 * row a person can find in a spreadsheet.
 */
final readonly class ImportTable
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
     * can be built against what is really in the file.
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
