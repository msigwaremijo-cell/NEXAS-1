<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a supplier debit note is correcting.
 *
 * A debit note, like a credit note, is not a free-standing document. It answers
 * a bill the company already posted, and this pair of columns is the link that
 * says which bill and which of its lines. Recording it here is what makes the
 * two guards possible: "how much of this bill is still open" and "which line's
 * goods are being sent back" both become questions asked of the link rather
 * than figures a caller has to remember.
 *
 * `bills.debit_note_for_bill_id` answers the first. The note may never exceed
 * what is still owed on the bill it corrects, and that ceiling is read from the
 * bill the note points at.
 *
 * `bill_lines.debit_note_for_line_id` answers the second, mirroring
 * `invoice_lines.credit_note_for_line_id`. It is what lets the posting find the
 * receipt a stock line came in on, so the goods leave at the price they arrived
 * at rather than whatever happens to be oldest on the shelf.
 *
 * Both columns are nullable: an ordinary bill and an ordinary bill line point
 * at nothing, because only a debit note names the bill it answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('debit_note_for_bill_id')
                ->nullable()
                ->after('document_type')
                ->constrained('bills')
                ->restrictOnDelete();

            $table->index('debit_note_for_bill_id', 'bills_debit_note_for_bill_idx');
        });

        Schema::table('bill_lines', function (Blueprint $table) {
            $table->foreignId('debit_note_for_line_id')
                ->nullable()
                ->after('expense_account_id')
                ->constrained('bill_lines')
                ->restrictOnDelete();

            $table->index('debit_note_for_line_id', 'bill_lines_debit_note_for_line_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // The foreign key goes first: MySQL holds the named index for the
            // constraint, so dropping the index while the key still stands is
            // refused. Dropping the column then takes the index with it.
            $table->dropForeign(['debit_note_for_bill_id']);
            $table->dropColumn('debit_note_for_bill_id');
        });

        Schema::table('bill_lines', function (Blueprint $table) {
            $table->dropForeign(['debit_note_for_line_id']);
            $table->dropColumn('debit_note_for_line_id');
        });
    }
};
