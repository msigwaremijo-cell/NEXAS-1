<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\Actions\DeleteAttachment;
use App\Domain\Files\Actions\StoreAttachment;
use App\Domain\Files\AttachmentTargets;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents filed against a record: upload, list, read back, withdraw.
 *
 * The whole surface is one idea — a document kept beside a record rather than
 * inside it. That is why a supplier's invoice can be filed against a journal
 * that has already been posted: the journal is not opened, not edited and not
 * re-versioned, and the document simply sits next to it. The requirement that a
 * posted record is never changed is met by never trying to change it.
 *
 * The file never becomes a URL of its own. It is written to a disk outside
 * public, and the only way to read it is the signed, expiring download route
 * below, which checks the signature, the caller's membership of the company
 * that owns it, and the right to read documents at all. A file that has been
 * withdrawn is gone from the store, and the route says so rather than serving a
 * blank.
 *
 * Nothing here decides anything about the file's contents. Whether a file is
 * the kind this company accepts is the shared validator's answer, reached
 * through StoreAttachment; whether it was written is the store's; whether the
 * record it names may have documents filed against it is the target register's.
 * This class validates the request shape, authorises it, and serialises the
 * record.
 */
class AttachmentController extends Controller
{
    /**
     * The documents filed against one record, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attachment::class);

        $data = $request->validate([
            'attachable_type' => ['required', 'string', Rule::in(array_keys(AttachmentTargets::types()))],
            'attachable_id' => ['required', 'string', 'size:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->companyId($request);
        $target = AttachmentTargets::resolve($data['attachable_type'], $data['attachable_id'], $companyId);

        $attachments = Attachment::query()
            ->where('company_id', $companyId)
            ->where('attachable_type', $target->getMorphClass())
            ->where('attachable_id', $target->getKey())
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Attachment $attachment): array => $this->present($attachment, $target),
                $attachments->items()
            ),
            'meta' => [
                'current_page' => $attachments->currentPage(),
                'per_page' => $attachments->perPage(),
                'total' => $attachments->total(),
                'last_page' => $attachments->lastPage(),
            ],
        ]);
    }

    /**
     * File a document against a record.
     *
     * The record is named by a short type and its public id, and is resolved
     * inside the caller's company before anything is written, so a document is
     * never filed against another tenant's record and never filed against one
     * that does not exist.
     */
    public function store(Request $request, StoreAttachment $store): JsonResponse
    {
        $this->authorize('create', Attachment::class);

        $data = $request->validate([
            'file' => ['required', 'file'],
            'attachable_type' => ['required', 'string', Rule::in(array_keys(AttachmentTargets::types()))],
            'attachable_id' => ['required', 'string', 'size:26'],
        ]);

        $companyId = $this->companyId($request);
        $target = AttachmentTargets::resolve($data['attachable_type'], $data['attachable_id'], $companyId);

        $attachment = $store(
            $companyId,
            $target,
            $request->file('file'),
            (int) $request->user()?->getKey(),
        );

        return response()->json(['data' => $this->present($attachment, $target)], 201);
    }

    /**
     * Serve the file.
     *
     * Behind the `signed` middleware as well as authentication and company
     * membership, so a link cannot be guessed and cannot be replayed once it
     * has expired. The signature is checked before the policy is reached; what
     * the policy adds is that the member holding a valid link must also be a
     * member of the company the document belongs to and hold the right to read
     * documents at all, which a leaked link on its own would not prove.
     *
     * A withdrawn document, or one whose file is gone, is a 404: there is
     * nothing here to read. The row may still exist — a document filed against
     * a posted record is marked withdrawn rather than removed — so the check is
     * on the file, not on the row.
     */
    public function download(Request $request, string $attachment): StreamedResponse
    {
        $record = $this->resolveAttachment($request, $attachment);

        $this->authorize('download', $record);

        abort_unless(
            Storage::disk((string) $record->disk)->exists((string) $record->path),
            404,
            'This document is no longer in the store.',
        );

        return Storage::disk((string) $record->disk)->download(
            (string) $record->path,
            $record->downloadName(),
            ['Content-Type' => (string) ($record->mime_type ?: 'application/octet-stream')],
        );
    }

    /**
     * Withdraw a document.
     *
     * Whether the row is removed outright or kept and marked withdrawn is the
     * action's decision, and turns on whether the record it was filed against
     * has been posted. Either way the withdrawal is written into the audit
     * chain and the file is deleted from the store.
     */
    public function destroy(Request $request, string $attachment, DeleteAttachment $delete): JsonResponse
    {
        $record = $this->resolveAttachment($request, $attachment);

        $this->authorize('delete', $record);

        $delete($record);

        return response()->json(['data' => [
            'id' => $record->ulid,
            'deleted' => true,
        ]]);
    }

    /**
     * Resolved inside the caller's company in the same query that reads it, so
     * naming another tenant's document is a 404 rather than a 403 that would
     * confirm it exists somewhere else. A withdrawn document — one that was
     * soft-deleted — is excluded by the same query, so it reads as gone.
     */
    private function resolveAttachment(Request $request, string $attachment): Attachment
    {
        return Attachment::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $attachment)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Attachment $attachment, Model $target): array
    {
        return [
            'id' => $attachment->ulid,
            'attachable_type' => AttachmentTargets::shortName((string) $attachment->attachable_type),
            'attachable_id' => $target->getAttribute('ulid'),
            'file_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size_bytes' => $attachment->size_bytes,
            'checksum_sha256' => $attachment->checksum_sha256,
            'uploaded_by_user_id' => $attachment->uploaded_by_user_id === null
                ? null
                : (int) $attachment->uploaded_by_user_id,
            'created_at' => $attachment->created_at?->toIso8601String(),
            'download_url' => $this->downloadUrl($attachment),
        ];
    }

    /**
     * A signed link that stops working on its own, well before anything is
     * withdrawn: the file outliving its own link is what signing the link is
     * for. Relative, so the link is the same whichever host the caller reached
     * the application on.
     */
    private function downloadUrl(Attachment $attachment): string
    {
        return URL::temporarySignedRoute(
            'api.v1.attachments.download',
            now()->addMinutes((int) config('nexas.attachments.link_ttl_minutes')),
            ['attachment' => $attachment->ulid],
            absolute: false,
        );
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
