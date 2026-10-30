<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cost centres a plan may be split across.
 *
 * A cost centre is a dimension of the plan, not of the books: the ledger
 * carries no cost centre column, so nothing here changes what a journal line
 * looks like. It exists so a budget can say "this much of the marketing spend",
 * which is a distinction the chart of accounts cannot draw without inventing an
 * account for every department.
 *
 * Modelled on branches: a company-scoped code and name, a status rather than a
 * delete, and a row_version so a change made by one member cannot silently
 * overwrite a change made by another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('status', 20)->default('active')->comment('active|inactive');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'cost_centers_company_code_unique');
            $table->index(['company_id', 'status'], 'cost_centers_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
