<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One item of property, plant or equipment, and what is left of it.
 *
 * An asset carries the two figures only it can know — what it cost and what it
 * may be sold for at the end of its life — and the date it went into service.
 * Which accounts every charge lands on is the category's answer, not the
 * asset's, so no account column appears here at all.
 *
 * accumulated_depreciation is held on the row rather than derived from the
 * ledger because the ledger's accumulated depreciation account is shared by
 * every asset of a category: a balance on that account cannot say how much of
 * it belongs to this vehicle. Keeping the per-asset figure here is what lets a
 * run know where it stands, and it is also what the register report totals —
 * a total the tie-out test proves against the ledger's own balance. An asset
 * whose row has drifted from the ledger is therefore a visible fault rather
 * than a silent one.
 *
 * depreciated_through records the end of the last period a run covered for
 * this asset, so a period that has already been depreciated cannot be charged
 * a second time even if a run is somehow asked for the same window again. The
 * company-level "one run per period" rule is the first defence; this is the
 * one that holds per asset.
 *
 * Status moves draft -> in_service -> disposed and never backwards. Draft and
 * in_service are both editable; once disposed the row is history and only a
 * reversal of the disposal journal can bring it back, which the action writes
 * and nothing else does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('status')->default('draft');
            $table->decimal('acquisition_cost', 19, 4);
            $table->decimal('salvage_value', 19, 4)->default(0);
            // The whole life in months. Straight-line depreciation is stated
            // month by month, so the life is stated the same way.
            $table->unsignedInteger('useful_life_months');
            // Null until the asset is placed in service. A draft has no life
            // running and is never depreciated.
            $table->date('in_service_date')->nullable();
            $table->decimal('accumulated_depreciation', 19, 4)->default(0);
            $table->date('depreciated_through')->nullable();
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 19, 4)->nullable();
            $table->foreignId('disposal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'assets_company_code_unique');
            $table->index(['company_id', 'status'], 'assets_company_status_idx');
            $table->index(['company_id', 'asset_category_id'], 'assets_company_category_idx');
        });
    }

    public function down(): void
    {
        // The foreign key column is dropped before the key it depends on: MySQL
        // refuses to drop a column an index still covers.
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['disposal_journal_id']);
        });

        Schema::dropIfExists('assets');
    }
};
