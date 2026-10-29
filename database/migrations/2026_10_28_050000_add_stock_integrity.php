<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rules stock needs beyond the columns it was created with.
 *
 * Three things are added here.
 *
 * A movement gets a status and a link to the movement it reverses. The link is
 * UNIQUE, which is the same trick `journals.reverses_journal_id` uses: it is
 * what makes "a movement can be reversed at most once" a fact of the schema
 * rather than a check two concurrent callers can both pass.
 *
 * A movement's direction is written into its own sign, so quantity is signed
 * data rather than a positive number plus a flag: a receipt is positive, an
 * issue or a transfer out is negative, and an adjustment is either. That gives
 * the whole module one invariant to check — the quantity on hand is the sum of
 * the movements — and it lets a CHECK refuse a row whose sign contradicts its
 * type, which a flag plus a positive quantity could never do.
 *
 * `stock_layer_consumptions` records every time a layer is drawn down, and by
 * which movement. A layer's `quantity_remaining` is therefore never moved
 * without a row here to say why. It is what makes an issue reversible at all:
 * the cost of the stock that left is only known after the layers that funded
 * it have been read, so it is written down at the moment of the issue instead
 * of being guessed at when it is put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `stock_movements` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'posted' AFTER `movement_date`"
        );

        DB::statement(
            'ALTER TABLE `stock_movements` ADD COLUMN `reverses_movement_id` BIGINT UNSIGNED NULL AFTER `journal_id`'
        );

        DB::statement(
            'ALTER TABLE `stock_movements` ADD CONSTRAINT `fk_stock_movements_reverses`'
            .' FOREIGN KEY (`reverses_movement_id`) REFERENCES `stock_movements` (`id`) ON DELETE RESTRICT'
        );

        // At most one reversal per movement. MySQL allows many NULLs in a
        // unique index, so the ordinary, unreversed movement is unaffected.
        DB::statement(
            'ALTER TABLE `stock_movements` ADD UNIQUE KEY `stock_movements_reverses_unique` (`reverses_movement_id`)'
        );

        // Holding is read per product and warehouse, which the existing
        // product_date index does not cover because the warehouse sits in the
        // middle of the key.
        DB::statement(
            'ALTER TABLE `stock_movements` ADD INDEX `stock_movements_holding_idx`'
            .' (`company_id`, `product_id`, `warehouse_id`, `movement_date`)'
        );

        foreach ($this->checks() as [$table, $name, $expression]) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }

        Schema::create('stock_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // The movement that drew the layer down, whether that was an issue,
            // a transfer out, a write-off, or the reversal of a receipt.
            $table->foreignId('movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('layer_id')->constrained('stock_layers')->restrictOnDelete();
            // Always positive: this is how much left the layer.
            $table->decimal('quantity', 19, 6);
            // The day the stock left, taken from the movement rather than from
            // the clock, so what the stock was worth on a past date can be
            // worked out rather than guessed: a layer's value at a date is what
            // it received, less what had been drawn from it by then.
            $table->date('consumed_on');
            $table->decimal('unit_cost', 19, 4);
            $table->decimal('total_cost', 19, 4);
            $table->timestamps();

            $table->index(['company_id', 'movement_id'], 'stock_layer_consumptions_movement_idx');
            $table->index(['layer_id'], 'stock_layer_consumptions_layer_idx');
            $table->index(['company_id', 'consumed_on'], 'stock_layer_consumptions_date_idx');
        });

        DB::statement(
            'ALTER TABLE `stock_layer_consumptions` ADD CONSTRAINT `chk_stock_layer_consumptions_quantity_positive`'
            .' CHECK (`quantity` > 0)'
        );

        DB::statement(
            'ALTER TABLE `stock_layer_consumptions` ADD CONSTRAINT `chk_stock_layer_consumptions_costs_non_negative`'
            .' CHECK (`unit_cost` >= 0 AND `total_cost` >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_layer_consumptions');

        foreach (array_reverse($this->checks()) as [$table, $name]) {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
        }

        // The foreign key has to go before the unique index it leans on:
        // MySQL refuses to drop an index a foreign key is using, and the
        // unique index on `reverses_movement_id` is the only one there is.
        DB::statement('ALTER TABLE `stock_movements` DROP FOREIGN KEY `fk_stock_movements_reverses`');
        DB::statement('ALTER TABLE `stock_movements` DROP INDEX `stock_movements_reverses_unique`');
        DB::statement('ALTER TABLE `stock_movements` DROP INDEX `stock_movements_holding_idx`');
        DB::statement('ALTER TABLE `stock_movements` DROP COLUMN `reverses_movement_id`');
        DB::statement('ALTER TABLE `stock_movements` DROP COLUMN `status`');
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function checks(): array
    {
        return [
            ['stock_movements', 'chk_stock_movements_status', "`status` IN ('posted','reversed')"],

            ['stock_movements', 'chk_stock_movements_type',
                "`movement_type` IN ('receipt','issue','adjustment','transfer_in','transfer_out')"],

            // The sign is not decoration: it is what makes the quantity on hand
            // a single SUM. A row whose sign contradicts its type is refused
            // here rather than corrected in application code.
            ['stock_movements', 'chk_stock_movements_sign',
                "(`movement_type` IN ('receipt','transfer_in') AND `quantity` > 0)"
                ." OR (`movement_type` IN ('issue','transfer_out') AND `quantity` < 0)"
                ." OR (`movement_type` = 'adjustment' AND `quantity` <> 0)"],

            ['stock_movements', 'chk_stock_movements_costs_non_negative',
                '`unit_cost` >= 0 AND `total_cost` >= 0'],

            ['stock_layers', 'chk_stock_layers_remaining_within_received',
                '`quantity_remaining` <= `quantity_received`'],
        ];
    }
};
