<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One committed CSV import, and the file contents it came from.
 *
 * The row exists for one reason: the same file must not be imported twice into
 * the same set of books. `file_hash` is the SHA-256 of the file's contents, so
 * a re-upload of the same spreadsheet — a fresh upload token, a different path,
 * the same bytes — is recognised as the same import rather than as a second
 * one. The hash is scoped to the company and the dataset, because the same file
 * legitimately means something different in another company's books, and a
 * chart of accounts and a customer list that happen to share a file are not the
 * same import.
 *
 * Only a commit writes a row. A dry run reads the file, checks every row and
 * writes nothing at all, so it leaves no trace here — which is what makes it
 * safe to run a file as many times as the operator likes before committing it.
 *
 * The natural keys a dataset carries — an account code, a party code, a product
 * SKU — are deliberately not the identity here. Those are checked per row
 * against what the books already hold, because a corrected file has a different
 * hash and must still be refused where it repeats a code that already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('dataset', 40)->comment('chart_of_accounts|customers|suppliers|products|opening_stock|opening_balances');
            $table->char('file_hash', 64);
            $table->string('file_name')->nullable();
            $table->unsignedInteger('rows_imported');
            $table->json('summary')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at');
            $table->timestamps();

            // The whole of the idempotency rule, enforced by the database rather
            // than by a check that a second request could win a race against.
            $table->unique(['company_id', 'dataset', 'file_hash'], 'import_runs_identity_unique');
            $table->index(['company_id', 'dataset', 'committed_at'], 'import_runs_recent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
