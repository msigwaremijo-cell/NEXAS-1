<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a credit note line is giving back.
 *
 * A credit note is not a free-standing document. Every line on it is a partial
 * or full reversal of one line of the invoice it corrects, and this column is
 * the link that says so. Recording it here is what makes over-crediting
 * impossible to get wrong: "how much of this invoice line has already been
 * credited" becomes a SUM over the lines that point back at it, rather than
 * something a caller has to remember and get right for itself.
 *
 * The column is nullable because an ordinary invoice line points at nothing —
 * only a credit note line names the invoice line it answers.
 *
 * The index is on the column alone because the one query that reads it is the
 * look-up by original line when a later credit note asks how much is left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('credit_note_for_line_id')
                ->nullable()
                ->after('revenue_account_id')
                ->constrained('invoice_lines')
                ->restrictOnDelete();

            $table->index('credit_note_for_line_id', 'invoice_lines_credit_note_for_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            // The foreign key goes first: MySQL holds the named index for the
            // constraint, so dropping the index while the key still stands is
            // refused. Dropping the column then takes the index with it.
            $table->dropForeign(['credit_note_for_line_id']);
            $table->dropColumn('credit_note_for_line_id');
        });
    }
};
