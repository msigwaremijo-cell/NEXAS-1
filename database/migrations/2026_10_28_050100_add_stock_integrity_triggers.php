<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integrity that a CHECK constraint cannot express, for the stock module.
 *
 * The same shape as the accounting triggers, and for the same reason. MySQL
 * CHECK constraints are row-scoped, so anything that has to reason about the
 * row's own past — "a posted movement may not be edited", "a layer's receipt
 * may not be rewritten" — can only live in a trigger.
 *
 * A posted movement cannot be changed, and the single permitted transition is
 * posted -> reversed. That is what makes the stock history trustworthy in the
 * same way the ledger is: a mistake is answered by an opposite movement, not
 * by an edit that leaves no trace.
 *
 * A layer's receipt side is frozen. Only `quantity_remaining` may move, and it
 * only ever moves down, so what a layer cost when it arrived cannot be
 * rewritten after the fact to change what an issue is worth.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $triggers = [
        ['stock_movements', 'trg_stock_movements_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    IF NOT (OLD.`status` <=> 'posted' AND NEW.`status` <=> 'reversed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A posted stock movement is immutable. Correct it by reversing it.';
    END IF;

    IF NOT (NEW.`company_id` <=> OLD.`company_id`)
       OR NOT (NEW.`ulid` <=> OLD.`ulid`)
       OR NOT (NEW.`product_id` <=> OLD.`product_id`)
       OR NOT (NEW.`warehouse_id` <=> OLD.`warehouse_id`)
       OR NOT (NEW.`movement_type` <=> OLD.`movement_type`)
       OR NOT (NEW.`movement_date` <=> OLD.`movement_date`)
       OR NOT (NEW.`quantity` <=> OLD.`quantity`)
       OR NOT (NEW.`unit_cost` <=> OLD.`unit_cost`)
       OR NOT (NEW.`total_cost` <=> OLD.`total_cost`)
       OR NOT (NEW.`source_type` <=> OLD.`source_type`)
       OR NOT (NEW.`source_id` <=> OLD.`source_id`)
       OR NOT (NEW.`journal_id` <=> OLD.`journal_id`)
       OR NOT (NEW.`reverses_movement_id` <=> OLD.`reverses_movement_id`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A posted stock movement is immutable; only its reversal may be recorded.';
    END IF;
END
SQL],

        ['stock_movements', 'trg_stock_movements_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A stock movement cannot be deleted. Reverse it instead.';
END
SQL],

        ['stock_layers', 'trg_stock_layers_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    IF NOT (NEW.`company_id` <=> OLD.`company_id`)
       OR NOT (NEW.`product_id` <=> OLD.`product_id`)
       OR NOT (NEW.`warehouse_id` <=> OLD.`warehouse_id`)
       OR NOT (NEW.`receipt_movement_id` <=> OLD.`receipt_movement_id`)
       OR NOT (NEW.`received_on` <=> OLD.`received_on`)
       OR NOT (NEW.`quantity_received` <=> OLD.`quantity_received`)
       OR NOT (NEW.`unit_cost` <=> OLD.`unit_cost`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'What a stock layer received, and what it cost, cannot change. Only what remains of it can.';
    END IF;
END
SQL],

        ['stock_layers', 'trg_stock_layers_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A stock layer cannot be deleted. Draw it down by consuming it.';
END
SQL],

        ['stock_layer_consumptions', 'trg_stock_layer_consumptions_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_layer_consumptions is append-only and cannot be updated.';
END
SQL],

        ['stock_layer_consumptions', 'trg_stock_layer_consumptions_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_layer_consumptions is append-only and cannot be deleted.';
END
SQL],
    ];

    public function up(): void
    {
        foreach ($this->triggers as [$table, $name, $timingEvent, $body]) {
            DB::unprepared("CREATE TRIGGER `{$name}` {$timingEvent} ON `{$table}` FOR EACH ROW {$body}");
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->triggers) as [, $name]) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }
    }
};
