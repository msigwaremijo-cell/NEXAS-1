<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts. Hierarchy is a materialised path of account codes plus a
 * denormalised depth, so subtree reports never need a recursive query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('account_type', 20)->comment('asset|liability|equity|revenue|expense');
            $table->string('normal_balance', 6)->comment('debit|credit');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('path', 255)->nullable()->comment('materialised path of ancestor codes');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_bank')->default(false);
            $table->char('currency_code', 3)->nullable();
            $table->string('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'account_type', 'status'], 'accounts_type_status_idx');
            $table->index(['company_id', 'path'], 'accounts_path_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
