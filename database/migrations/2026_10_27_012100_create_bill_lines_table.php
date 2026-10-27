<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('description');
            $table->decimal('quantity', 19, 6)->default(0);
            $table->decimal('unit_price', 19, 4)->default(0);
            $table->decimal('discount_rate', 9, 6)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_rate', 9, 6)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('line_subtotal', 19, 4)->default(0);
            $table->decimal('line_total', 19, 4)->default(0);
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['bill_id', 'line_no'], 'bill_lines_bill_line_unique');
            $table->index(['company_id', 'tax_code_id'], 'bill_lines_company_tax_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
    }
};
