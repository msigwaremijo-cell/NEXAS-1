<?php

namespace App\Domain\Importing\DTOs;

use App\Domain\Importing\Enums\ImportDataset;

/**
 * What one run of an import found, and what it did about it.
 *
 * The same shape answers a dry run and a commit, so an operator can read the
 * report one way twice rather than meeting two different documents. The two
 * flags say which happened: `dryRun` is what was asked for and `committed` is
 * what was done — a dry run is never committed, and a commit that found any
 * error is refused before it reaches here.
 */
final readonly class ImportReport
{
    /**
     * @param  list<ImportRowError>  $errors
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public ImportDataset $dataset,
        public bool $dryRun,
        public bool $committed,
        public int $rowsTotal,
        public int $rowsValid,
        public int $rowsImported,
        public array $errors = [],
        public array $summary = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset->value,
            'dry_run' => $this->dryRun,
            'committed' => $this->committed,
            'rows_total' => $this->rowsTotal,
            'rows_valid' => $this->rowsValid,
            'rows_imported' => $this->rowsImported,
            'errors' => array_map(
                static fn (ImportRowError $error): array => $error->toArray(),
                $this->errors,
            ),
            'summary' => $this->summary,
        ];
    }
}
