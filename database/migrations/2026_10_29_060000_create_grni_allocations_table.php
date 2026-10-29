<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The link between a supplier bill and the receipts it settles.
 *
 * Stage 8B separates the two events a purchase used to be one of. The receipt
 * moves the stock and credits GRNI — goods received not invoiced; the bill then
 * clears GRNI and creates the payable. Nothing else records which receipts a
 * bill cleared, and without that the same delivery could be billed twice, or
 * billed for more than ever arrived.
 *
 * Each row is one bill line's draw on one receipt: how much of it the line
 * settled, at what the receipt was booked at, and what that came to. The value
 * is what the line takes out of GRNI; the difference between it and the line's
 * own net is the purchase price variance.
 *
 * The row is written rather than recomputed for the same reason a layer
 * consumption is: an allocation is a fact about what a bill did, and replaying
 * it later against a shelf that has moved on would give a different answer.
 *
 * `(bill_line_id, receipt_movement_id)` is unique because one line cannot draw
 * the same receipt down twice in one posting; two lines that both touch the
 * receipt are two rows, which is what the FIFO plan produces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grni_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // A bill line only exists while its bill does.
            $table->foreignId('bill_line_id')->constrained('bill_lines')->cascadeOnDelete();
            // The receipt movement whose goods the line settles. Restricted:
            // a receipt a bill has cleared is part of the history, so it
            // cannot be removed from underneath the allocation.
            $table->foreignId('receipt_movement_id')->constrained('stock_movements')->restrictOnDelete();
            // Always positive: this is how much of the receipt the line took.
            $table->decimal('quantity', 19, 6);
            // The price the receipt was booked at, snapshot rather than read
            // back, so what was cleared stays what was cleared.
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('value', 19, 4);
            $table->timestamps();

            $table->unique(
                ['bill_line_id', 'receipt_movement_id'],
                'grni_allocations_line_receipt_unique'
            );
            $table->index(['company_id', 'receipt_movement_id'], 'grni_allocations_receipt_idx');
        });

        DB::statement(
            'ALTER TABLE `grni_allocations` ADD CONSTRAINT `chk_grni_allocations_quantity_positive`'
            .' CHECK (`quantity` > 0)'
        );

        DB::statement(
            'ALTER TABLE `grni_allocations` ADD CONSTRAINT `chk_grni_allocations_value_non_negative`'
            .' CHECK (`unit_cost` >= 0 AND `value` >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('grni_allocations');
    }
};
