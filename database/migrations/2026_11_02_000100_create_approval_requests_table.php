<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The approvals queue.
 *
 * One row per document waiting on, or having received, a decision. The document
 * is named by type and by both of its identifiers: `document_id` is what makes
 * the row a real reference the queue can look the document up by, and
 * `document_ulid` is the handle the API and the browser hold, because no
 * internal key is ever exposed on the surface.
 *
 * A document is queued once. The unique key over (company_id, document_type,
 * document_id) is what enforces that, so a second raise of the same document is
 * refused by the database rather than by a read-then-write race. A decision is
 * therefore final for that document: a rejected document is not approved later
 * by rewriting the row, because the queue is the record of what was asked and
 * what was decided, and rewriting it would erase the first answer.
 *
 * There is no delete and no soft-delete column for the same reason: a decision
 * is history. `row_version` is what lets a caller holding a stale copy be
 * refused rather than allowed to overwrite a decision already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 40)->comment('journal|bill|payment|credit_note|debit_note|asset_disposal');
            $table->unsignedBigInteger('document_id');
            $table->char('document_ulid', 26);
            $table->string('document_reference', 120)->nullable();
            $table->string('status', 20)->default('pending')->comment('pending|approved|rejected');
            // The member who raised the document for a decision. This is the
            // author the no-self-approval rule is measured against, so it is
            // written at the moment the request is raised rather than inferred
            // later from the document, which the document's own module may have
            // written first.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'document_id'], 'approval_requests_document_unique');
            $table->index(['company_id', 'status', 'created_at'], 'approval_requests_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
