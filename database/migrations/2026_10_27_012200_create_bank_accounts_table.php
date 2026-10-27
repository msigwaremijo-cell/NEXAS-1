<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('name');
            $table->string('bank_name')->nullable();
            $table->string('account_number', 40)->nullable();
            $table->char('currency_code', 3);
            $table->string('account_type', 16)->default('bank')->comment('bank|cash|mobile_money');
            $table->decimal('opening_balance', 19, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status'], 'bank_accounts_company_status_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
