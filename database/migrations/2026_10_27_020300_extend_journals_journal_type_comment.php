<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `transfer` to the list of values journals.journal_type may hold.
 *
 * The column comment is the schema's own record of that list, and
 * App\Domain\Accounting\Enums\JournalType is written to mirror it, so the new
 * value is added in both places rather than only in PHP. Only the comment
 * changes: the column is a plain varchar(20) with no CHECK constraint
 * enumerating its values, and the integrity trigger compares OLD against NEW
 * for equality rather than against a fixed list, so no trigger has to move.
 */
return new class extends Migration
{
    private const VALUES = 'general|sales|purchase|receipt|payment|credit_note|opening|closing|reversal';

    public function up(): void
    {
        $this->recomment(self::VALUES.'|transfer');
    }

    public function down(): void
    {
        $this->recomment(self::VALUES);
    }

    private function recomment(string $values): void
    {
        Schema::table('journals', function (Blueprint $table) use ($values): void {
            $table->string('journal_type', 20)->comment($values)->change();
        });
    }
};
