<?php

namespace App\Domain\Importing\Actions;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Importing\DTOs\DatasetPlan;
use App\Domain\Importing\DTOs\ImportReport;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportTable;
use App\Domain\Importing\Exceptions\DuplicateImportException;
use App\Domain\Importing\Exceptions\ImportingException;
use App\Domain\Importing\Importers\DatasetImporter;
use App\Domain\Importing\Importers\DatasetImporterRegistry;
use App\Domain\Importing\Services\CsvImportReader;
use App\Models\ImportRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads a stored import file through its importer and either reports or writes.
 *
 * The order is the whole of the design:
 *
 *   1. read the stored file, or refuse a token that names nothing;
 *   2. check the mapping describes this file, before a single row is read, so a
 *      mapping error is the first thing the caller hears;
 *   3. refuse a file that has already been committed for this company and
 *      dataset — the same bytes, for the same job, are imported once;
 *   4. read the file into a plan, which is what both a dry run and a commit
 *      act on, so the report an operator approves is the report the commit
 *      obeys rather than a second opinion;
 *   5. stop there for a dry run, and stop with a refusal if the plan has any
 *      complaint;
 *   6. write the rows and the record that they were written in one transaction,
 *      with the audit entry beside them.
 *
 * The identity a repeat is refused on is the file's content hash, scoped to the
 * company and the dataset. It is recomputed from the stored file rather than
 * trusted from the upload, so a file edited after it was uploaded is a
 * different import, which is right: it is different bytes.
 *
 * The unique index behind that rule is what makes it a fact rather than a hope.
 * Two commits of the same file racing each other both pass step 3 and only one
 * wins the insert; the loser is refused by the index, which is why the write is
 * wrapped and a duplicate-key error is turned into the same refusal.
 */
final class RunImport
{
    public function __construct(
        private readonly CsvImportReader $reader,
        private readonly DatasetImporterRegistry $importers,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(ImportRequest $request): ImportReport
    {
        $table = $this->readUpload($request);
        $hash = $this->fileHash($request);

        $importer = $this->importers->for($request->dataset);

        $this->assertMappingFitsTheFile($importer, $request, $table);

        if (! $request->dryRun) {
            $this->assertTheFileHasNotBeenImported($request, $hash);
        }

        $plan = $importer->prepare($request, $table);

        if ($request->dryRun) {
            return $this->report($request, $table, $plan, committed: false, summary: $plan->summary);
        }

        if ($plan->hasErrors()) {
            throw ImportingException::cannotCommit($plan->errors);
        }

        return $this->write($request, $table, $importer, $plan, $hash);
    }

    /**
     * The stored file the request names, or a refusal naming the token.
     *
     * The token is checked against the ULID alphabet before it is used to build
     * a path: caller-supplied text must not reach the filesystem, and a token
     * that is not a ULID could not have been issued by this system.
     */
    private function readUpload(ImportRequest $request): ImportTable
    {
        $path = $this->path($request);

        return $this->reader->read(Storage::disk(StoreImportUpload::DISK)->path($path));
    }

    private function fileHash(ImportRequest $request): string
    {
        return (string) hash_file('sha256', Storage::disk(StoreImportUpload::DISK)->path($this->path($request)));
    }

    private function path(ImportRequest $request): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $request->uploadToken) !== 1) {
            throw ImportingException::unknownUpload($request->uploadToken);
        }

        $path = StoreImportUpload::pathFor($request->companyId, $request->uploadToken);

        if (! Storage::disk(StoreImportUpload::DISK)->exists($path)) {
            throw ImportingException::unknownUpload($request->uploadToken);
        }

        return $path;
    }

    /**
     * The mapping has to describe this file: it may only name fields the
     * importer knows, it has to name every field the file cannot be read
     * without, no column may be asked to do two jobs, and every column it names
     * has to be a column the file actually carries.
     */
    private function assertMappingFitsTheFile(DatasetImporter $importer, ImportRequest $request, ImportTable $table): void
    {
        $fields = $importer->fields();

        /** @var array<string, string> $mapped  field => the column it was given */
        $mapped = [];

        /** @var array<string, string> $used  column => the field using it */
        $used = [];

        foreach ($request->mapping as $field => $column) {
            $field = (string) $field;

            if (! array_key_exists($field, $fields)) {
                throw ImportingException::unknownField($field, array_keys($fields));
            }

            $column = trim($column);

            if ($column === '') {
                continue;
            }

            if (isset($used[$column])) {
                throw ImportingException::columnMappedTwice($column);
            }

            $used[$column] = $field;

            if (! in_array($column, $table->headers, true)) {
                throw ImportingException::unknownColumn($fields[$field], $column, $table->headers);
            }

            $mapped[$field] = $column;
        }

        foreach ($importer->requiredFields() as $field) {
            if (! isset($mapped[$field])) {
                throw ImportingException::missingColumn($fields[$field]);
            }
        }
    }

    /**
     * Refuse a file whose exact bytes have already been committed here.
     */
    private function assertTheFileHasNotBeenImported(ImportRequest $request, string $hash): void
    {
        $existing = ImportRun::query()
            ->where('company_id', $request->companyId)
            ->where('dataset', $request->dataset->value)
            ->where('file_hash', $hash)
            ->first();

        if ($existing === null) {
            return;
        }

        throw DuplicateImportException::alreadyImported(
            $request->dataset->label(),
            (string) $existing->file_name,
            (int) $existing->rows_imported,
        );
    }

    /**
     * @param  string  $hash  the stored file's SHA-256, recomputed from disk
     */
    private function write(
        ImportRequest $request,
        ImportTable $table,
        DatasetImporter $importer,
        DatasetPlan $plan,
        string $hash,
    ): ImportReport {
        try {
            return DB::transaction(function () use ($request, $table, $importer, $plan, $hash): ImportReport {
                $summary = $importer->commit($request, $plan->rows);

                $run = ImportRun::create([
                    'ulid' => (string) Str::ulid(),
                    'company_id' => $request->companyId,
                    'dataset' => $request->dataset->value,
                    'file_hash' => $hash,
                    'file_name' => $request->fileName,
                    'rows_imported' => $plan->validCount(),
                    'summary' => $summary,
                    'imported_by_user_id' => $request->userId,
                    'committed_at' => now(),
                ]);

                $this->audit->record('import.committed', $run, null, [
                    'dataset' => $request->dataset->value,
                    'rows' => $plan->validCount(),
                    'file_hash' => $hash,
                ]);

                return $this->report($request, $table, $plan, committed: true, summary: $summary);
            });
        } catch (QueryException $race) {
            // The unique index on (company, dataset, file hash) is what makes
            // "already imported" a fact rather than a hope. Reaching it means a
            // second commit of the same file committed between the check above
            // and this insert, and the whole transaction has already rolled
            // back by the time this runs.
            if (($race->errorInfo[1] ?? null) === 1062) {
                throw DuplicateImportException::raced($request->dataset->label());
            }

            throw $race;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function report(
        ImportRequest $request,
        ImportTable $table,
        DatasetPlan $plan,
        bool $committed,
        array $summary,
    ): ImportReport {
        return new ImportReport(
            dataset: $request->dataset,
            dryRun: $request->dryRun,
            committed: $committed,
            rowsTotal: $table->rowCount(),
            rowsValid: $plan->validCount(),
            rowsImported: $committed ? $plan->validCount() : 0,
            errors: $plan->errors,
            summary: $summary,
        );
    }
}
