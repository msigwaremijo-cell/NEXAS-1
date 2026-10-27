<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay protection for mutating APIs. request_hash lets the server detect a
 * reused key carrying a different payload, which is a client bug rather than
 * a safe retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('scope', 60);
            $table->string('key', 80);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('in_progress')->comment('in_progress|completed|failed');
            $table->string('response_type', 60)->nullable();
            $table->unsignedBigInteger('response_id')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'scope', 'key'], 'idempotency_keys_scope_unique');
            $table->index(['company_id', 'created_at'], 'idempotency_keys_company_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
