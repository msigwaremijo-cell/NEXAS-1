<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The level at which an item wants reordering.
 *
 * Nullable on purpose: most items are never watched, and a zero would be a
 * claim that the shelf should never be empty rather than that nobody is
 * watching it. The low-stock job reads this against what the layers still hold.
 *
 * A quantity, so DECIMAL(19,6) to match every other stock figure rather than
 * a float that could not round-trip it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('reorder_level', 19, 6)->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('reorder_level');
        });
    }
};
