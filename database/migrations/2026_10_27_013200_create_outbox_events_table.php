<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox: an event is written in the same transaction as the
 * state change, then a worker publishes it and marks it processed. This keeps
 * side effects from being lost when a queue is unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 80);
            $table->string('aggregate_type', 80)->nullable();
            $table->unsignedBigInteger('aggregate_id')->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('pending')->comment('pending|processing|processed|failed');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'outbox_events_dispatch_idx');
            $table->index(['aggregate_type', 'aggregate_id'], 'outbox_events_aggregate_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
