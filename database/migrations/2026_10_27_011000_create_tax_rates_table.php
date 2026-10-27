<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dated tax rates. Rate is a decimal fraction (0.180000 = 18%), never a float.
 * A code may have many rates over time; resolution picks the row whose
 * effective window contains the document date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $table->decimal('rate', 9, 6);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'tax_code_id', 'effective_from'], 'tax_rates_effective_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
