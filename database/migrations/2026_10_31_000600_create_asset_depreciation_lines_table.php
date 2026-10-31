<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one run charged each asset, one row per asset per period.
 *
 * The depreciation journal says the same thing in the ledger, but a journal
 * line names an account rather than an asset, so it cannot say which asset a
 * charge belongs to. That matters twice over:
 *
 *   - the register's accumulated_depreciation is a per-asset figure, so a run
 *     has to know what it charged each asset, and a reversal has to know what
 *     to give back;
 *   - UNIQUE(asset_id, fiscal_period_id) is a database-level floor under the
 *     "one charge per asset per period" rule, so a run that raced another
 *     cannot double-charge even if the application check above it missed.
 *
 * These rows are derived from the run and are not themselves the record: the
 * posted journal is. They are deleted when the run's journal is reversed, so
 * the period may be run again once the ledger has been put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $table->foreignId('journal_id')->constrained('journals')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->timestamps();

            // One charge per asset per period, enforced by the database rather
            // than only by the run that writes it.
            $table->unique(['asset_id', 'fiscal_period_id'], 'asset_depreciation_lines_asset_period_unique');
            $table->index(['company_id', 'fiscal_period_id'], 'asset_depreciation_lines_company_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_lines');
    }
};
