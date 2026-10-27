<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per account / per period snapshot maintained by a queued job. Trial balance
 * and general ledger can read it instead of scanning every journal line;
 * last_journal_line_id makes an incremental refresh possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_debit', 19, 4)->default(0);
            $table->decimal('opening_credit', 19, 4)->default(0);
            $table->decimal('period_debit', 19, 4)->default(0);
            $table->decimal('period_credit', 19, 4)->default(0);
            $table->decimal('closing_debit', 19, 4)->default(0);
            $table->decimal('closing_credit', 19, 4)->default(0);
            $table->unsignedBigInteger('last_journal_line_id')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'account_id', 'fiscal_period_id'], 'ledger_balances_scope_unique');
            $table->index(['company_id', 'fiscal_period_id'], 'ledger_balances_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_balances');
    }
};
