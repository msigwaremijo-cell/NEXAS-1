<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless document numbering. The row is locked with SELECT ... FOR UPDATE
 * inside the posting transaction before next_number is incremented, so two
 * concurrent postings for the same company/type/year cannot share a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 30)->comment('journal|invoice|bill|payment|credit_note');
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->string('prefix', 12)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();

            $table->unique(['company_id', 'document_type', 'fiscal_year_id'], 'document_sequences_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
