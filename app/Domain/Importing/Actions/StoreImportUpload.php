<?php

namespace App\Domain\Importing\Actions;

use App\Domain\Files\Services\UploadValidator;
use App\Domain\Importing\DTOs\ImportUpload;
use App\Domain\Importing\Enums\ImportDataset;
use App\Domain\Importing\Exceptions\ImportingException;
use App\Domain\Importing\Services\CsvImportReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores an uploaded migration file and reads it well enough to preview.
 *
 * This is the first half of an import, and it is separate from the second for
 * one reason: a mapping cannot be chosen until somebody has seen what the
 * file's columns are actually called. Upload, look, map, import.
 *
 * The file is read here, at upload time, even though nothing is imported yet.
 * Reading it now is what turns "we stored a file" into "we stored a file we can
 * read": a file that is empty, headerless, ragged or duplicated is refused
 * before it is kept, so the store never fills up with files nobody will ever be
 * able to import. A refused file is deleted rather than left behind.
 *
 * `Storage::disk('imports')` is a private disk rooted under storage/app/private,
 * which the storage/app/.gitignore file excludes, so an uploaded file is
 * neither web readable nor committed. The path is company/token, so the token
 * alone is not enough to reach a file: the caller has to already be working in
 * that company.
 *
 * The file's own hash is computed here and carried on the upload, so the person
 * deciding whether to import can see the identity the run will be refused on if
 * the same bytes have been through before.
 */
final class StoreImportUpload
{
    public const DISK = 'imports';

    /**
     * How many rows the preview carries: enough to see the shape of every
     * column without turning the response into a copy of the file.
     */
    private const PREVIEW_ROWS = 5;

    public function __construct(
        private readonly CsvImportReader $reader,
        private readonly UploadValidator $validator,
    ) {}

    public function __invoke(int $companyId, ImportDataset $dataset, UploadedFile $file): ImportUpload
    {
        // The same gate every other upload passes through, narrowed to the one
        // type a migration file can be, so anything that is not really a CSV is
        // refused before it is written here.
        $this->validator->validate(
            $file,
            $dataset->label().' import file',
            ['csv'],
            (int) config('nexas.imports.max_kilobytes'),
        );

        $token = (string) Str::ulid();
        $path = self::pathFor($companyId, $token);

        $stored = Storage::disk(self::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        if (! is_string($stored) || $stored === '') {
            throw ImportingException::uploadNotStored();
        }

        try {
            $table = $this->reader->read(Storage::disk(self::DISK)->path($path));
        } catch (Throwable $refusal) {
            Storage::disk(self::DISK)->delete($path);

            throw $refusal;
        }

        return new ImportUpload(
            companyId: $companyId,
            dataset: $dataset,
            token: $token,
            originalName: $file->getClientOriginalName(),
            sizeBytes: (int) $file->getSize(),
            fileHash: (string) hash_file('sha256', Storage::disk(self::DISK)->path($path)),
            delimiter: $table->delimiter,
            headers: $table->headers,
            preview: $table->preview(self::PREVIEW_ROWS),
            rowCount: $table->rowCount(),
        );
    }

    /**
     * Where one upload lives inside the private import disk.
     *
     * The token is a ULID and the company id is an integer, so the path cannot
     * walk out of the disk: there is no point at which caller-supplied text
     * reaches it unvalidated.
     */
    public static function pathFor(int $companyId, string $token): string
    {
        return $companyId.'/'.$token.'.csv';
    }
}
