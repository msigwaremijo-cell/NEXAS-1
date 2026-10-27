<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->char('from_currency_code', 3);
            $table->char('to_currency_code', 3);
            $table->decimal('rate', 19, 8);
            $table->date('effective_date');
            $table->string('source', 40)->nullable();
            $table->timestamps();

            $table->unique(
                ['company_id', 'from_currency_code', 'to_currency_code', 'effective_date'],
                'exchange_rates_pair_date_unique'
            );
            $table->index(['company_id', 'effective_date'], 'exchange_rates_company_date_idx');
            $table->foreign('from_currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('to_currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
