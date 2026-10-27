<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoice lines. tax_rate is a snapshot of the tax_rates row that applied on
 * the document date, so re-pricing a tax code later never rewrites history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
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
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['invoice_id', 'line_no'], 'invoice_lines_invoice_line_unique');
            $table->index(['company_id', 'tax_code_id'], 'invoice_lines_company_tax_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
