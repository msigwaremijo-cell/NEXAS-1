<?php

namespace App\Domain\Importing\DTOs;

/**
 * What an importer made of a file: the rows it would write, and what is wrong
 * with the rows it would not.
 *
 * The split is the whole point of a dry run. A plan with no errors is a file
 * that can be committed; a plan with errors is a report an operator can act on
 * without a single row having been written. Because both the dry run and the
 * commit read the file through the same importer, the report an operator
 * approves is the report the commit acts on rather than a second opinion.
 */
final readonly class DatasetPlan
{
    /**
     * @param  list<array<string, mixed>>  $rows  ready to write, in file order
     * @param  list<ImportRowError>  $errors
     * @param  array<string, mixed>  $summary  what the importer found, for the report
     */
    public function __construct(
        public array $rows,
        public array $errors = [],
        public array $summary = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function validCount(): int
    {
        return count($this->rows);
    }

    public function errorCount(): int
    {
        return count($this->errors);
    }
}
