<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('invoice_no', 30);
            $table->string('document_type', 16)->default('invoice')->comment('invoice|credit_note');
            $table->string('status', 20)->default('draft')->comment('draft|issued|partially_paid|paid|void');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 19, 8)->default(1);
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('discount_total', 19, 4)->default(0);
            $table->decimal('tax_total', 19, 4)->default(0);
            $table->decimal('total', 19, 4)->default(0);
            $table->decimal('amount_paid', 19, 4)->default(0);
            $table->string('reference', 60)->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('credit_note_for_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'invoice_no'], 'invoices_company_number_unique');
            $table->index(['company_id', 'status', 'due_date'], 'invoices_status_due_idx');
            $table->index(['company_id', 'customer_id', 'status'], 'invoices_customer_status_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
