<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report a human asked to be rendered to a file, and the state of rendering
 * it.
 *
 * An export is a job rather than a download because the reports worth
 * exporting are the heavy ones: a trial balance over a year, a balance sheet
 * with a prior column. Building one inline would hold a request open for as
 * long as the ledger takes to read, so the request writes this row, a worker
 * renders the file, and the caller is handed back something to poll.
 *
 * The row is the record of the file, not the file: `path` points at a disk
 * that lives outside public and `expires_at` says when the cleanup may delete
 * it. `error` is kept so a failure says what went wrong rather than only that
 * it did, because a job that fails silently is indistinguishable from one
 * that was never run.
 *
 * `parameters` holds the window the report was asked for — the dates, the
 * compare flag — as asked, so reissuing the same request or reading the record
 * back a week later does not require reconstructing it from the caller's
 * memory. `type` and `format` are the two halves of what to build; the
 * registry, not this table, decides which types exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Who asked. Kept rather than cascaded, like every other author
            // reference in the schema: the record of who requested what must
            // outlive the member.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('format');
            $table->string('status')->default('queued');
            $table->json('parameters')->nullable();
            $table->string('disk');
            // Null until the job has written something, and null again after
            // the cleanup has removed the file.
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // When the cleanup may take the file away. Indexed because the
            // prune command reads the table by exactly this column.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // The list screen reads a company's exports newest first, and the
            // poll reads one by its public identifier; both lead with company.
            $table->index(['company_id', 'status'], 'exports_company_status_idx');
            $table->index('expires_at', 'exports_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
