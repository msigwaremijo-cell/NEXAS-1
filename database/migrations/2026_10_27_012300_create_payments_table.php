<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer receipts and supplier payments share one table. direction says
 * which way the money moved; allocated_amount plus unapplied_amount is the
 * auditable split of how much of this payment has been applied to documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('payment_no', 30);
            $table->string('direction', 16)->comment('receipt|cash_payment');
            $table->string('party_type', 20)->comment('customer|supplier');
            $table->unsignedBigInteger('party_id');
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->date('payment_date');
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate', 19, 8)->default(1);
            $table->decimal('amount', 19, 4);
            $table->decimal('allocated_amount', 19, 4)->default(0);
            $table->decimal('unapplied_amount', 19, 4)->default(0);
            $table->string('method', 20)->default('bank')->comment('bank|cash|mobile_money|cheque');
            $table->string('reference', 60)->nullable();
            $table->string('status', 20)->default('draft')->comment('draft|posted|void');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->string('idempotency_key', 80)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'payment_no'], 'payments_company_number_unique');
            $table->unique(['company_id', 'idempotency_key'], 'payments_idempotency_unique');
            $table->index(['company_id', 'party_type', 'party_id'], 'payments_party_idx');
            $table->index(['company_id', 'status', 'payment_date'], 'payments_status_date_idx');
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
