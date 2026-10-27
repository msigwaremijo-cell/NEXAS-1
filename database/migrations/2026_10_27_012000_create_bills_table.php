<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier bills. Unlike invoices these carry an explicit approval gate:
 * draft -> pending_approval -> approved -> posted, so a bill can never reach
 * the ledger without a named approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('bill_no', 30);
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->string('document_type', 16)->default('bill')->comment('bill|debit_note');
            $table->string('status', 20)->default('draft')->comment('draft|pending_approval|approved|partially_paid|paid|void');
            $table->date('bill_date');
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
            $table->string('approval_status', 20)->default('not_required')->comment('not_required|pending|approved|rejected');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'bill_no'], 'bills_company_number_unique');
            $table->index(['company_id', 'status', 'due_date'], 'bills_status_due_idx');
            $table->index(['company_id', 'supplier_id', 'status'], 'bills_supplier_status_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
