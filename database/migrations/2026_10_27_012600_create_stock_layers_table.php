<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->date('received_on');
            $table->decimal('quantity_received', 19, 6);
            $table->decimal('quantity_remaining', 19, 6);
            $table->decimal('unit_cost', 19, 4);
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'product_id', 'warehouse_id', 'received_on'], 'stock_layers_fifo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layers');
    }
};
