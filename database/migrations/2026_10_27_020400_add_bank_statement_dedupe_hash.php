<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every imported statement line a fingerprint of its own content.
 *
 * A statement file is downloaded from a bank and uploaded by a human, and the
 * second upload of an overlapping file is the normal case rather than an
 * attack. The fingerprint is what lets the importers and, later, the
 * reconciliation engine recognise a movement they have already seen without
 * comparing six columns by hand.
 *
 * The unique index is what makes "already imported" a fact MySQL knows instead
 * of a check PHP hopes wins the race: two imports of the same file arriving
 * together cannot both write the same line. It is scoped to the account,
 * because the same amount on the same day with the same narration can
 * legitimately appear on two different accounts' statements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->char('dedupe_hash', 64)
                ->after('reference')
                ->comment('sha256 of value_date, debit, credit, reference and description');

            $table->unique(
                ['company_id', 'bank_account_id', 'dedupe_hash'],
                'bank_statement_lines_dedupe_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropUnique('bank_statement_lines_dedupe_unique');
            $table->dropColumn('dedupe_hash');
        });
    }
};
