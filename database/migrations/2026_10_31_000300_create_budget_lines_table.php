<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One planned amount for one account, in one cost centre, inside one period.
 *
 * The amount is signed the same way the profit and loss statement presents the
 * account — revenue and expense both positive on their own side — so a line is
 * read the way the report it is compared against reads. No CHECK constraint
 * bounds the sign: a contra account is planned as a negative figure, and a plan
 * is a statement of intent rather than a movement, so the database has no
 * business refusing one that the accountant meant.
 *
 * cost_center_id is nullable, meaning "the company as a whole": a plan may be
 * written before anybody has decided how to split it. That nullability is why
 * the uniqueness rule is not a plain unique index — MySQL treats every NULL as
 * distinct, so two lines on the same account with no cost centre would both be
 * accepted. cost_center_key collapses the two cases into one value so the rule
 * holds whether or not a cost centre was named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4)->default(0);
            // Written by MySQL, never by the application: it exists only to make
            // the unique index below null-safe.
            $table->unsignedBigInteger('cost_center_key')->storedAs('ifnull(cost_center_id, 0)');
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'cost_center_key'], 'budget_lines_account_unique');
            $table->index(['company_id', 'account_id'], 'budget_lines_company_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
