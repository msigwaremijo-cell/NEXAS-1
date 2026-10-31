<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\DTOs\StatementUpload;
use App\Domain\Banking\Exceptions\BankStatementException;
use App\Domain\Banking\Services\CsvStatementReader;
use App\Domain\Files\Services\UploadValidator;
use App\Models\BankAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores an uploaded statement file and reads it well enough to preview.
 *
 * This is the first half of the import, and it is separate from the second for
 * one reason: the mapping cannot be chosen until somebody has seen what the
 * file's columns are actually called. Upload, look, map, import.
 *
 * The file is read here, at upload time, even though nothing is imported yet.
 * Reading it now is what turns "we stored a file" into "we stored a file we can
 * read": a file that is empty, headerless, ragged or duplicated is refused
 * before it is kept, so the store never fills up with statements nobody will
 * ever be able to import. A refused file is deleted rather than left behind.
 *
 * `Storage::disk('statements')` is a private disk rooted under
 * storage/app/private, which the storage/app/.gitignore file excludes, so an
 * uploaded statement is neither web readable nor committed. The path is
 * company/account/token, which means the token alone is not enough to reach a
 * file: the caller has to already be working in that company and on that
 * account.
 */
final class StoreStatementUpload
{
    public const DISK = 'statements';

    /**
     * How many movements the preview carries. Enough to see the shape of every
     * column - a signed amount, a money in and out pair, an empty optional cell
     * - without turning the response into a copy of the file.
     */
    private const PREVIEW_ROWS = 5;

    /**
     * The ceiling for one statement file. A year of a busy current account as
     * CSV is a few hundred kilobytes, and anything far past this is not a
     * statement file.
     */
    private const MAX_KILOBYTES = 5120;

    public function __construct(
        private readonly CsvStatementReader $reader,
        private readonly UploadValidator $validator,
    ) {}

    public function __invoke(int $companyId, BankAccount $bankAccount, UploadedFile $file): StatementUpload
    {
        // The same gate every other upload passes through, narrowed to the one
        // type a statement can be. Before this, the only thing standing between
        // this endpoint and the store was a size check: any file at all could be
        // written here so long as it was under five megabytes and the reader did
        // not object to it. Now it has to actually be a CSV.
        $this->validator->validate($file, 'statement file', ['csv'], self::MAX_KILOBYTES);

        $token = (string) Str::ulid();
        $path = self::pathFor($companyId, (int) $bankAccount->getKey(), $token);

        $stored = Storage::disk(self::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        if (! is_string($stored) || $stored === '') {
            throw BankStatementException::uploadNotStored();
        }

        try {
            $table = $this->reader->read(Storage::disk(self::DISK)->path($path));
        } catch (Throwable $refusal) {
            Storage::disk(self::DISK)->delete($path);

            throw $refusal;
        }

        return new StatementUpload(
            companyId: $companyId,
            bankAccountId: (int) $bankAccount->getKey(),
            token: $token,
            originalName: $file->getClientOriginalName(),
            sizeBytes: (int) $file->getSize(),
            delimiter: $table->delimiter,
            headers: $table->headers,
            preview: $table->preview(self::PREVIEW_ROWS),
            rowCount: $table->rowCount(),
        );
    }

    /**
     * Where one upload lives inside the private statement disk.
     *
     * The token is a ULID and the two ids are integers, so the path cannot walk
     * out of the disk: there is no point at which caller-supplied text reaches
     * it unvalidated.
     */
    public static function pathFor(int $companyId, int $bankAccountId, string $token): string
    {
        return $companyId.'/'.$bankAccountId.'/'.$token.'.csv';
    }
}
