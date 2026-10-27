<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('value_date');
            $table->string('description')->nullable();
            $table->string('reference', 60)->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->decimal('balance_after', 19, 4)->nullable();
            $table->string('status', 20)->default('unmatched')->comment('unmatched|matched|ignored');
            $table->string('import_batch', 40)->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'bank_account_id', 'value_date'], 'bank_statement_lines_lookup');
            $table->index(['company_id', 'status'], 'bank_statement_lines_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }
};
