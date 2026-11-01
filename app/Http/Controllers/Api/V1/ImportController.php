<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Importing\Actions\RunImport;
use App\Domain\Importing\Actions\StoreImportUpload;
use App\Domain\Importing\DTOs\ImportRequest;
use App\Domain\Importing\DTOs\ImportUpload;
use App\Domain\Importing\Enums\ImportDataset;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bringing a company's existing books in as a file.
 *
 * Two routes per dataset, in the same order the bank statement import uses and
 * for the same reason: the columns of a file exported from another system are
 * named by that system, and nothing in the file settles whether a column called
 * "No" is an account code or an account number. Upload, look at what is really
 * in the file, then say what each column is.
 *
 * The dataset is the path, not the body, because which dataset is being
 * imported decides which rights the caller must hold. The rights are the ones
 * the same job would need done by hand rather than a new set of import rights —
 * importing a chart of accounts is maintaining the chart of accounts, and
 * importing opening balances posts a journal — and a caller who does not hold
 * all of them is refused before a file is read.
 *
 * Nothing on this surface is a policy. There is no importable record to
 * authorise against, because the thing being written is a file's contents
 * rather than a row the caller names, so the rights are asked of the matrix
 * through the gate instead — one `authorize` call for each right the dataset
 * names, made in the method it protects rather than one call down. A dataset
 * can name more than one right, and holding half of them is not holding them.
 *
 * The dry run is the same route as the commit, told apart by `dry_run`. It
 * reads the file and reports every complaint without writing anything, so the
 * report an operator approves is the report the commit then obeys rather than a
 * second opinion read from a table that has since moved.
 */
class ImportController extends Controller
{
    /**
     * Store the file and hand back what is in it, so a mapping can be built
     * against the column names the file really carries.
     */
    public function storeUpload(
        Request $request,
        string $dataset,
        StoreImportUpload $store,
    ): JsonResponse {
        $dataset = $this->dataset($dataset);

        // Every right the dataset needs, checked in the method it protects.
        foreach ($dataset->permissions() as $permission) {
            $this->authorize($permission);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('nexas.imports.max_kilobytes')],
        ]);

        $upload = $store($this->companyId($request), $dataset, $request->file('file'));

        return response()->json(['data' => $this->presentUpload($upload)], 201);
    }

    /**
     * Read the file under a mapping the caller chose, and either report what it
     * found or write it.
     *
     * The mapping is validated for shape here; everything that decides whether
     * the mapping is true of this particular file — a field the importer does
     * not know, a column the file does not carry, a required column left
     * unmapped — belongs to the action, in the same way an unbalanced journal
     * is the posting rules' business rather than the controller's.
     *
     * `as_of` is required only for the two datasets that begin from a date,
     * because asking every import for one would invite a date to be sent that
     * means nothing. A committed run answers 201; a dry run answers 200, since
     * nothing was created.
     */
    public function run(Request $request, string $dataset, RunImport $run): JsonResponse
    {
        $dataset = $this->dataset($dataset);

        // Every right the dataset needs, checked in the method it protects.
        foreach ($dataset->permissions() as $permission) {
            $this->authorize($permission);
        }

        $data = $request->validate([
            'upload_token' => ['required', 'string', 'size:26'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'dry_run' => ['sometimes', 'boolean'],
            'as_of' => [$dataset->needsAsOf() ? 'required' : 'sometimes', 'nullable', 'date_format:Y-m-d'],
            // The file's own name, carried back from the upload so a refusal
            // that says "you have already imported this file" can name it. It is
            // a label recorded for the operator rather than something read, so
            // it is not used to find anything.
            'file_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $report = $run(new ImportRequest(
            companyId: $this->companyId($request),
            dataset: $dataset,
            uploadToken: $data['upload_token'],
            mapping: $this->mapping($data['mapping']),
            dryRun: (bool) ($data['dry_run'] ?? false),
            asOf: $this->text($data, 'as_of'),
            fileName: $this->text($data, 'file_name'),
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => $report->toArray()], $report->committed ? 201 : 200);
    }

    /**
     * The dataset named by the path, or a 404 naming it.
     *
     * Resolved from the enum rather than accepted as free text, so a dataset
     * that does not exist is refused before any right is checked or any file is
     * touched.
     */
    private function dataset(string $dataset): ImportDataset
    {
        return ImportDataset::tryFrom($dataset)
            ?? abort(404, sprintf('There is no "%s" import.', $dataset));
    }

    /**
     * The company the caller proved membership of, set by the `company`
     * middleware that runs after authentication.
     */
    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }

    /**
     * The mapping reduced to field => column, dropping anything that is not a
     * column name.
     *
     * A field sent as an empty string means "no column", the same as not
     * sending it at all, so it is dropped here rather than carried into a
     * mapping that would then be refused for naming a column the file does not
     * have.
     *
     * @param  array<mixed>  $mapping
     * @return array<string, string>
     */
    private function mapping(array $mapping): array
    {
        $clean = [];

        foreach ($mapping as $field => $column) {
            if (is_string($field) && is_string($column) && trim($column) !== '') {
                $clean[$field] = trim($column);
            }
        }

        return $clean;
    }

    /**
     * A field sent as an empty string means "nothing", the same as not sending
     * it at all.
     *
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUpload(ImportUpload $upload): array
    {
        return [
            'dataset' => $upload->dataset->value,
            'upload_token' => $upload->token,
            'file_name' => $upload->originalName,
            'size_bytes' => $upload->sizeBytes,
            // Shown so "you have already imported this file" is something the
            // caller can check for themselves rather than take on trust.
            'file_hash' => $upload->fileHash,
            'delimiter' => $upload->delimiter,
            'headers' => $upload->headers,
            'row_count' => $upload->rowCount,
            'preview' => $upload->preview,
        ];
    }
}
