<?php

namespace App\Domain\Importing\Importers;

use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportTable;

/**
 * One dataset a company can bring in as a file.
 *
 * An importer does two things and nothing else: it reads the file under the
 * caller's mapping and says what it found, and — given rows it has already
 * found sound — it writes them. The reading half never writes, which is what
 * lets the same code answer a dry run and a commit with the same report.
 *
 * `fields()` is the importer's vocabulary: the fields a mapping may name, each
 * with the label a person reads in a refusal. `requiredFields()` is the subset
 * without which the file cannot be read at all. A mapping that names a column
 * the file does not carry, or uses one column for two fields, is refused before
 * a single row is read, so the first error an operator sees is about the shape
 * of the mapping rather than a wrong value twenty rows down.
 *
 * Nothing here opens a transaction. The run action does, so that the rows a
 * commit writes and the audit entry that records them commit together.
 */
interface DatasetImporter
{
    /**
     * The fields a mapping may name, as field => the label a person reads.
     *
     * @return array<string, string>
     */
    public function fields(): array;

    /**
     * The fields the mapping must name for the file to be readable.
     *
     * @return list<string>
     */
    public function requiredFields(): array;

    /**
     * Read the whole file and say what would be written.
     */
    public function prepare(ImportRequest $request, ImportTable $table): DatasetPlan;

    /**
     * Write the rows a clean plan produced.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed> a summary for the report
     */
    public function commit(ImportRequest $request, array $rows): array;
}
