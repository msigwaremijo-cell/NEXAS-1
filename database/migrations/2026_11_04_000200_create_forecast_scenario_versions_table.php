<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One saved state of a forecast scenario. Immutable by construction.
 *
 * Every time a scenario's assumptions are saved — a first save, a change, or a
 * restore of an older version — a new row is written here and never edited
 * again. The triggers installed beside this table refuse an UPDATE or a DELETE
 * outright, so the history is immutable at the database and not merely by the
 * convention of the service that writes it. A change of assumption is a new
 * version, and restoring an old assumption is a new version that copies it:
 * the past is never rewritten.
 *
 * The row carries its own `company_id` as well as the scenario it belongs to,
 * and a trigger refuses a version whose company disagrees with its scenario, so
 * a version can never be filed under the wrong tenant even by a bad insert.
 *
 * Growth is a percentage held to four decimal places, matching the money scale:
 * `10` is ten per cent, `-10` is minus ten. The adjustments are the named
 * account overrides applied on top of the growth, kept as a list of
 * `{account_id, amount}` in the same shape the API accepts.
 *
 * There is `created_at` and no `updated_at`: a row is written once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_scenario_versions', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('forecast_scenario_id')->constrained('forecast_scenarios')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('type', 20)->comment('base|optimistic|pessimistic');
            $table->decimal('revenue_growth', 9, 4)->default(0);
            $table->decimal('expense_growth', 9, 4)->default(0);
            $table->json('adjustments')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // A version number is unique within its scenario, which is what
            // makes `current_version_no` on the parent a real pointer and what
            // stops two concurrent saves from both claiming version N.
            $table->unique(['forecast_scenario_id', 'version_no'], 'forecast_scenario_versions_no_unique');
            $table->index(['company_id', 'forecast_scenario_id', 'version_no'], 'forecast_scenario_versions_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_scenario_versions');
    }
};
