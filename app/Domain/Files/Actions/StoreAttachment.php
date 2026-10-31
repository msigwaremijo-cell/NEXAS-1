<?php

namespace App\Domain\Files\Actions;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Files\Exceptions\FilesException;
use App\Domain\Files\Services\UploadValidator;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Files a document beside a record.
 *
 * Two steps that must not come apart: the file is written to the private
 * store, and a row is written that says where it went. Both happen inside one
 * transaction, so a row never points at a file that was not written and a file
 * never sits in the store that no row accounts for.
 *
 * What this deliberately does not do is touch the record the document is filed
 * against. It reads that record only to learn what it is and what its key is.
 * That is what lets a receipt be added to a journal that has already been
 * posted: the journal's contents are frozen, and the document is kept beside
 * it rather than inside it.
 *
 * The file itself never keeps the name it arrived with. It is stored under a
 * fresh ULID, so nothing a caller sends determines where anything lands, and
 * the name they did send is kept on the row for the way back.
 */
final class StoreAttachment
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FilesException
     */
    public function __invoke(
        int $companyId,
        Model $target,
        UploadedFile $file,
        ?int $userId,
    ): Attachment {
        $validated = $this->validator->validate($file, 'attachment');

        $disk = $this->disk();
        $path = $validated->pathFor($companyId);

        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        if (! is_string($stored) || $stored === '') {
            throw FilesException::notStored($validated->originalName);
        }

        return DB::transaction(function () use ($companyId, $target, $validated, $userId, $disk, $path): Attachment {
            $attachment = Attachment::create([
                'company_id' => $companyId,
                'attachable_type' => $target->getMorphClass(),
                'attachable_id' => $target->getKey(),
                'original_name' => $validated->originalName,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $validated->mimeType,
                'size_bytes' => $validated->sizeBytes,
                'checksum_sha256' => $validated->checksum,
                'uploaded_by_user_id' => $userId,
            ]);

            $this->audit->record('attachment.added', $attachment, null, [
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ]);

            return $attachment;
        });
    }

    /**
     * The private disk documents are kept on. Read from configuration so a
     * deployment can move the store without a code change.
     */
    private function disk(): string
    {
        return (string) config('nexas.attachments.disk');
    }
}
