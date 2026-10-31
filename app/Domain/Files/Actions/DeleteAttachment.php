<?php

namespace App\Domain\Files\Actions;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Files\AttachmentTargets;
use App\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Withdraws a document that was filed beside a record.
 *
 * The rule this turns on is whether the record it was filed against has been
 * posted. A document filed against a posted record is part of the account of
 * that entry: the row is kept and marked withdrawn, so somebody looking back
 * can see that something was filed there and is now gone, rather than seeing a
 * record that looks as though nothing ever was. A document filed against a
 * draft is simply removed, because nothing has been asserted yet that anyone
 * needs to be able to look back at.
 *
 * Either way the file itself is deleted from the store, and either way the
 * withdrawal is written into the audit chain first, inside the same
 * transaction. The row surviving while the file does not is deliberate: what
 * survives is the record that a document existed, which is the thing an audit
 * asks about, not the document.
 */
final class DeleteAttachment
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(Attachment $attachment): void
    {
        // Asked before anything is written, because once the row is gone the
        // relation it was read through is gone with it.
        $target = $attachment->attachable;
        $posted = $target !== null && AttachmentTargets::isPosted($target);

        DB::transaction(function () use ($attachment, $posted): void {
            $this->audit->record('attachment.removed', $attachment, [
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'path' => $attachment->path,
            ], null);

            if ($posted) {
                $attachment->delete();
            } else {
                $attachment->forceDelete();
            }
        });

        Storage::disk((string) $attachment->disk)->delete((string) $attachment->path);
    }
}
