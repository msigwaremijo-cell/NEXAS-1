<?php

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Models\Attachment;
use App\Models\User;

/**
 * Documents filed against records.
 *
 * Reading a document is part of reading the books, so `attachment.view` is held
 * broadly; filing one and withdrawing one are writes, so they sit behind
 * `attachment.manage`. Note what is *not* in this policy: nothing that changes
 * the record a document is filed against. Adding a document to a posted record
 * is allowed and normal — it is filing something beside an entry, not editing
 * the entry — which is exactly why the surface can be offered at all.
 *
 * Membership is checked by hand, like every other company-scoped policy: the
 * record handed in may belong to a company the caller has never been a member
 * of, and a permission check alone would not notice. The controller also
 * resolves the row inside the caller's company, so another tenant's document is
 * a 404 before it ever reaches here; this is the second half of the same gate.
 */
class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ATTACHMENT_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(AccessMatrix::PERMISSION_ATTACHMENT_MANAGE);
    }

    public function view(User $user, Attachment $attachment): bool
    {
        return $user->belongsToCompany((int) $attachment->company_id)
            && $user->can(AccessMatrix::PERMISSION_ATTACHMENT_VIEW);
    }

    /**
     * Serving the file is reading the same document the row describes, so it
     * is the same right and the same membership test.
     */
    public function download(User $user, Attachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $user->belongsToCompany((int) $attachment->company_id)
            && $user->can(AccessMatrix::PERMISSION_ATTACHMENT_MANAGE);
    }
}
