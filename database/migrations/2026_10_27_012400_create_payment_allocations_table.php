<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of one payment went against one invoice or bill. document_type +
 * document_id is a polymorphic reference kept deliberately FK-free so a
 * single allocation table can serve both invoices and bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 20)->comment('invoice|bill');
            $table->unsignedBigInteger('document_id');
            $table->decimal('amount', 19, 4);
            $table->foreignId('journal_line_id')->nullable()->constrained('journal_lines')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['payment_id', 'document_type', 'document_id'], 'payment_allocations_target_unique');
            $table->index(['company_id', 'document_type', 'document_id'], 'payment_allocations_document_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
