<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal lines.
 *
 * posting_year / posting_month are STORED generated columns derived from
 * posting_date, so they can never drift from the date they describe.
 *
 * Partitioning note: MySQL 8.4 InnoDB does not support foreign keys on a
 * partitioned table. Referential integrity to journals/accounts was chosen
 * over RANGE partitioning, so this table stays unpartitioned. The covering
 * index journal_lines_account_covering serves the general ledger and trial
 * balance scans; partitioning is deferred until real volume justifies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 19, 4)->default(0);
            $table->decimal('credit', 19, 4)->default(0);
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 19, 8)->default(1);
            $table->decimal('base_debit', 19, 4)->default(0);
            $table->decimal('base_credit', 19, 4)->default(0);
            $table->date('posting_date');
            $table->unsignedSmallInteger('posting_year')->storedAs('year(posting_date)');
            $table->unsignedTinyInteger('posting_month')->storedAs('month(posting_date)');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->string('party_type', 30)->nullable()->comment('customer|supplier');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['journal_id', 'line_no'], 'journal_lines_journal_line_unique');
            $table->index(['company_id', 'account_id', 'posting_date', 'id'], 'journal_lines_account_covering');
            $table->index(['company_id', 'posting_year', 'posting_month'], 'journal_lines_period_idx');
            $table->index(['company_id', 'party_type', 'party_id'], 'journal_lines_party_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
