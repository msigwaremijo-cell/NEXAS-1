<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named set of forecast assumptions the company has chosen to keep.
 *
 * Until now a forecast stored nothing: a scenario was a set of figures handed
 * to one read and forgotten. This is the first forecast artefact that persists,
 * and it persists only the *assumptions* — never a projected figure. The
 * projection is still derived on demand from the plan and the run-rate, so a
 * saved scenario cannot go stale and cannot drift into an actual report.
 *
 * The row is the scenario's identity, not its content: `current_version_no`
 * points at the version that is in force, and the assumptions themselves live
 * in forecast_scenario_versions, one immutable row per saved state. Editing a
 * scenario writes a new version and moves this pointer; it never rewrites a
 * version that already exists, so the history of what the company assumed is
 * kept even as the assumption changes.
 *
 * A name is unique within a company. Two scenarios may share a name across
 * tenants, which is why the unique key carries `company_id` rather than being
 * on `name` alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_scenarios', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('type', 20)->default('base')->comment('base|optimistic|pessimistic');
            // The version in force. It moves forward as versions are written
            // and may be pointed at an older one by a restore, which writes a
            // new version rather than rolling this back.
            $table->unsignedInteger('current_version_no')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'forecast_scenarios_name_unique');
            $table->index(['company_id', 'updated_at'], 'forecast_scenarios_list_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_scenarios');
    }
};
