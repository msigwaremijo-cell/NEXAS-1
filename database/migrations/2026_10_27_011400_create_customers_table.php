<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('tin', 20)->nullable();
            $table->string('vrn', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->char('currency_code', 3);
            $table->foreignId('receivable_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('credit_limit', 19, 4)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('status', 20)->default('active')->comment('active|inactive|blocked');
            $table->string('notes')->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status'], 'customers_company_status_idx');
            $table->index(['company_id', 'name'], 'customers_company_name_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
