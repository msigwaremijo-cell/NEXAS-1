<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The plan for one fiscal period.
 *
 * Deliberately one header per company per period: a budget is what the company
 * intends to earn and spend between two dates, so a second plan for the same
 * dates would be a second answer to one question. It is a plan and never a
 * posting — nothing in this table or its lines can reach the ledger, and no
 * journal is written when one changes.
 *
 * There is no soft-delete column. A plan is not history the way a posted
 * document is: it is rewritten while the period is still ahead, and the audit
 * chain keeps what it held before each rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // A budget covers a fiscal period rather than a hand-picked range,
            // so the calendar decides where the plan begins and ends.
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('notes')->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_period_id'], 'budgets_company_period_unique');
            $table->index(['company_id', 'created_at'], 'budgets_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
