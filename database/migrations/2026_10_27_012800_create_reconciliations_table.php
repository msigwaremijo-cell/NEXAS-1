<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_statement_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_line_id')->constrained('journal_lines')->restrictOnDelete();
            $table->decimal('matched_amount', 19, 4);
            $table->string('match_type', 20)->default('manual')->comment('manual|auto|rule');
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_statement_line_id', 'journal_line_id'], 'reconciliations_pair_unique');
            $table->index(['company_id', 'reconciled_at'], 'reconciliations_company_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
