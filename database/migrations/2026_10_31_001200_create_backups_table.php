<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One nightly backup, and what the run that wrote it found.
 *
 * Nothing here carries a `company_id`, and that is the point: a backup is the
 * whole database — every company, every member, every filed document — so it
 * belongs to no single tenant. Everything else in this schema is scoped to a
 * company; this table is the one record of the system as a whole.
 *
 * The row is the record of the file, not the file. `path` points at a disk
 * outside public holding a file that cannot be read without the key kept off
 * the server, and `checksum_sha256` is what a restore checks the file against
 * before it trusts a byte of it.
 *
 * `table_count`, `row_count` and `file_count` are what the run saw as it took
 * the copy, kept so a later restore drill has something to compare against
 * without unwrapping the file first. `error` is kept so a night that failed
 * says why rather than only that it did.
 *
 * `pruned_at` is when the retention policy took the file away. The row stays:
 * that a backup was taken on a given night, and how big it was, is a fact
 * worth keeping long after the file itself has gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Which disk the file was written to, so a deployment that moves
            // backups elsewhere does not need the old rows rewritten.
            $table->string('disk');
            $table->string('status')->default('running');
            // Moved through null: set only once the file is written, and
            // cleared when the retention policy removes it.
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            // Of the file as it sits on the disk — the encrypted bytes, so a
            // restore can tell a truncated copy from a whole one.
            $table->char('checksum_sha256', 64)->nullable();
            // Which schema was dumped. Kept because the same row is read back
            // by a restore that must know what it is restoring into what.
            $table->string('database_name');
            $table->unsignedInteger('table_count')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            // When the retention policy took the file away.
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();

            // The prune reads by completion time and the list reads newest
            // first; both lead with the status they care about.
            $table->index(['status', 'completed_at'], 'backups_status_completed_idx');
            $table->index('pruned_at', 'backups_pruned_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
