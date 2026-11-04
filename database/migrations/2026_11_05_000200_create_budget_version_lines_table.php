<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One planned amount inside one version of a plan.
 *
 * A version speaks about a whole fiscal year, so every line names the month it
 * belongs to: the grid the company edits is twelve columns wide and a version
 * that only knew a year total could not be edited or compared month by month.
 * The row is therefore one amount for one fiscal period, one account and one
 * cost centre, inside one version.
 *
 * The table is immutable in the same way forecast_scenario_versions is: a
 * change writes a new version rather than editing an existing one, and triggers
 * beside this table refuse an UPDATE or a DELETE outright. That is what makes
 * "preserve all previous versions" true at the database and not merely a
 * convention of the service that writes it.
 *
 * The row carries its own `company_id` as well as the version it belongs to,
 * and a trigger refuses a line whose company disagrees with its version and who
 * whose fiscal period disagrees with that same company, so a line can never be
 * filed under the wrong tenant even by a bad insert.
 *
 * The amount and the null-safe cost centre key are exactly as budget_lines
 * states them: signed the way the profit and loss statement presents the
 * account, and keyed so that two lines on the same account with no cost centre
 * are still refused. Nothing here reaches the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_version_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 4)->default(0);
            // Written by MySQL, never by the application: it exists only to make
            // the unique index below null-safe.
            $table->unsignedBigInteger('cost_center_key')->storedAs('ifnull(cost_center_id, 0)');
            $table->timestamp('created_at')->nullable();

            // One amount per period, account and cost centre inside a version,
            // which is what stops a version from holding two answers to the same
            // question.
            $table->unique(
                ['budget_version_id', 'fiscal_period_id', 'account_id', 'cost_center_key'],
                'budget_version_lines_grain_unique'
            );
            // The read the grid performs: every line of one version for one
            // period, in account order.
            $table->index(['company_id', 'fiscal_period_id', 'account_id'], 'budget_version_lines_grid_idx');
            $table->index(['company_id', 'budget_version_id'], 'budget_version_lines_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_version_lines');
    }
};
