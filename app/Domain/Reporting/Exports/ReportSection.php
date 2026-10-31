<?php

namespace App\Domain\Reporting\Exports;

/**
 * One titled block of a report: a heading, the columns beneath it, the rows,
 * and the line that totals them.
 *
 * A section is deliberately a grid of strings rather than a list of objects.
 * The two writers — CSV and PDF — both want the same thing, which is what is
 * printed and where, and neither should have to be told how to read a report's
 * own payload. Whatever the report's shape, it arrives here already flattened:
 * a trial balance is one section, a profit and loss is two, and the writers
 * never learn the difference.
 */
final readonly class ReportSection
{
    /**
     * @param  list<string>  $columns  the header cells, aligned with each row
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $total  a final row aligned with $columns
     */
    public function __construct(
        public string $heading,
        public array $columns,
        public array $rows,
        public ?array $total = null,
    ) {}
}
