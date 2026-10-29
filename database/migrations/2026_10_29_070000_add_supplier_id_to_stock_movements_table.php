<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a delivery of stock came from.
 *
 * Stage 8B separates the two events a purchase used to be one of: the receipt
 * moves the stock and credits GRNI, and the bill later clears GRNI. That only
 * works if a receipt can say whose goods it was, because a bill may clear the
 * receipts of its own supplier and no others — otherwise one supplier's invoice
 * could be settled against another supplier's delivery.
 *
 * The column is nullable because most movements have no supplier at all: an
 * issue, a count, a transfer and a non-purchase receipt such as opening stock
 * are not anybody's delivery. A null supplier is what keeps such a receipt out
 * of reach of every bill, since the clearing query matches on supplier.
 *
 * The index is on `(company_id, supplier_id, product_id)` because that is
 * exactly how a bill looks for the receipts it may clear, and it is the query
 * that runs on every posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('warehouse_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->index(
                ['company_id', 'supplier_id', 'product_id'],
                'stock_movements_supplier_product_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_supplier_product_idx');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
