<?php

namespace App\Models;

use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document waiting on, or having received, a decision.
 *
 * The queue is generic on purpose: a journal, a supplier bill, a payment, a
 * credit or debit note and an asset disposal all wait in the same line, because
 * what they have in common is the thing the queue is about — a second pair of
 * eyes — and not the shape of the document.
 *
 * `requested_by_user_id` is the author the no-self-approval rule is measured
 * against. It is written when the request is raised and not read back from the
 * document, so the rule holds for a document whose module records no author of
 * its own, and holds it as of the moment the request was made.
 *
 * There is no delete: a decision is history, and the row is kept as it was.
 */
#[Fillable([
    'ulid',
    'company_id',
    'document_type',
    'document_id',
    'document_ulid',
    'document_reference',
    'status',
    'requested_by_user_id',
    'decided_by_user_id',
    'decision_reason',
    'decided_at',
    'row_version',
])]
class ApprovalRequest extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => ApprovalDocumentType::class,
            'status' => ApprovalStatus::class,
            'row_version' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }

    /**
     * The document the request names, resolved inside the request's own company.
     *
     * The lookup is scoped to the company so the queue can never be used to
     * reach a document belonging to another tenant, and it applies the extra
     * `document_type` condition for the three kinds that share the `bills`
     * table, so a credit note cannot be resolved by a request that names a
     * supplier bill.
     */
    public function document(): ?Model
    {
        $class = $this->document_type->modelClass();
        $documentType = $this->document_type->documentTypeColumn();

        return $class::query()
            ->where('company_id', (int) $this->company_id)
            ->where('ulid', $this->document_ulid)
            ->when(
                $documentType !== null,
                fn (Builder $query): Builder => $query->where('document_type', $documentType),
            )
            ->first();
    }
}
