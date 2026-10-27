<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('name');
            $table->string('unit', 16)->default('pcs');
            $table->string('product_type', 16)->default('goods')->comment('goods|service');
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('unit_cost', 19, 4)->default(0);
            $table->decimal('sale_price', 19, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'status'], 'products_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
