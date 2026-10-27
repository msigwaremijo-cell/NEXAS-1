<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal headers. Posted journals are immutable: there is deliberately no
 * soft-delete column, and corrections happen only by posting a reversal
 * journal that points back through reverses_journal_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->string('journal_no', 30);
            $table->string('journal_type', 20)->comment('general|sales|purchase|receipt|payment|credit_note|opening|closing|reversal');
            $table->string('status', 20)->default('draft')->comment('draft|posted|reversed');
            $table->date('posting_date');
            $table->date('document_date')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('memo')->nullable();
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 19, 8)->default(1);
            $table->decimal('total_debit', 19, 4)->default(0);
            $table->decimal('total_credit', 19, 4)->default(0);
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('reverses_journal_id')->nullable()->unique()->constrained('journals')->restrictOnDelete();
            $table->foreignId('reversed_by_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'journal_no'], 'journals_company_number_unique');
            $table->unique(['company_id', 'idempotency_key'], 'journals_idempotency_unique');
            $table->index(['company_id', 'posting_date', 'status'], 'journals_posting_lookup');
            $table->index(['company_id', 'source_type', 'source_id'], 'journals_source_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
