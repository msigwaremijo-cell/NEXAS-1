<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments filed against a posted record are withdrawn softly.
 *
 * The row is the record that a document existed, what it was called, what the
 * bytes hashed to and who filed it, and it is what the audit chain needs in
 * order to say a document was withdrawn rather than that one never was. On a
 * draft record there is nothing to preserve, so the row goes with the file.
 *
 * The file itself always goes: "deleted" has to mean deleted, or the store
 * fills with documents nobody can be shown. What survives on a posted record
 * is the account of the document, not the document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
