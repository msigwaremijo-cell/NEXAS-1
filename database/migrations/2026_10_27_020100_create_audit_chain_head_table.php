<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row head of the audit hash chain.
 *
 * The chain lives here rather than being read from audit_logs itself because
 * MySQL forbids a trigger from taking a locking read on the table it is
 * defined on (error 1442), and a plain read of the newest row would let two
 * concurrent inserts chain onto the same predecessor. Locking this one row
 * with SELECT ... FOR UPDATE serialises every audit write, so the chain can
 * only ever grow by one link at a time.
 *
 * The chain is global, not per company: that way tampering with any company's
 * history is detectable from the same verify pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary()->comment('always 1');
            $table->char('last_hash', 64)->nullable();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->unsignedBigInteger('chain_length')->default(0);
            $table->timestamps();
        });

        DB::table('audit_chain_head')->insert([
            'id' => 1,
            'last_hash' => null,
            'last_id' => null,
            'chain_length' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');
    }
};
