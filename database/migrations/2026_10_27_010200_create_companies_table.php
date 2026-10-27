<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('tin', 20)->nullable()->comment('Tanzania Taxpayer Identification Number');
            $table->string('vrn', 20)->nullable()->comment('VAT registration number');
            $table->char('country_code', 2)->default('TZ');
            $table->char('base_currency_code', 3)->default('TZS');
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);
            $table->string('timezone', 64)->default('Africa/Dar_es_Salaam');
            $table->string('status', 20)->default('active');
            $table->json('settings')->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status', 'companies_status_idx');
            $table->foreign('base_currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
