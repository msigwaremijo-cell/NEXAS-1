<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tax code is a named rule (VAT, withholding, excise, levy). It carries no
 * rate: rates live in tax_rates as dated rows so a rate change is data, never
 * a code change, and historical documents keep the rate they were issued with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('tax_type', 20)->comment('vat|withholding|excise|levy|stamp');
            $table->string('applies_to', 12)->comment('sales|purchases|both');
            $table->boolean('is_inclusive')->default(false)->comment('rate is baked into the line price');
            $table->boolean('is_recoverable')->default(true);
            $table->foreignId('output_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('input_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'applies_to', 'status'], 'tax_codes_scope_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
