<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The cross-row half of the reconciliation lock.
 *
 * Completion is what turns a set of tentative matches into a statement somebody
 * signed off. Once that has happened the evidence behind the sign-off must stay
 * exactly as it was, so MySQL is asked to refuse, not the application:
 *
 *   reconciliations      a row whose reconciled_at is set is frozen. Nothing may
 *                        update it, nothing may delete it, and in particular it
 *                        may not be un-stamped: that would silently reopen a
 *                        completed reconciliation while leaving its audit entry
 *                        claiming it was finished. The one transition allowed is
 *                        the stamping itself, because the trigger tests OLD.
 *   bank_statement_lines a line matched by a completed reconciliation is frozen
 *                        too. Editing what the bank said after the statement was
 *                        signed off would leave the signed figures describing a
 *                        document that no longer exists.
 *
 * The statement-line side asks about a different table, which is why it cannot
 * be a CHECK: the row alone does not know whether a reconciliation covering it
 * has been completed.
 *
 * Ordering matters in the completing transaction. A statement line's status is
 * changed at match time, while the reconciliation is still tentative, and
 * completion only stamps reconciled_at afterwards. Nothing therefore needs to
 * write to a statement line after this lock closes, which is exactly the
 * property these triggers preserve.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $triggers = [
        ['reconciliations', 'trg_reconciliations_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    IF OLD.`reconciled_at` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This reconciliation is complete and cannot be changed.';
    END IF;
END
SQL],

        ['reconciliations', 'trg_reconciliations_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    IF OLD.`reconciled_at` IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A completed reconciliation cannot be deleted.';
    END IF;
END
SQL],

        ['bank_statement_lines', 'trg_bank_statement_lines_reconciliation_lock_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    DECLARE v_completed INT DEFAULT 0;

    SELECT COUNT(*) INTO v_completed
      FROM `reconciliations`
     WHERE `bank_statement_line_id` = OLD.`id`
       AND `reconciled_at` IS NOT NULL;

    IF v_completed > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This statement line was locked by a completed reconciliation and cannot be changed.';
    END IF;
END
SQL],

        ['bank_statement_lines', 'trg_bank_statement_lines_reconciliation_lock_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    DECLARE v_completed INT DEFAULT 0;

    SELECT COUNT(*) INTO v_completed
      FROM `reconciliations`
     WHERE `bank_statement_line_id` = OLD.`id`
       AND `reconciled_at` IS NOT NULL;

    IF v_completed > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This statement line was locked by a completed reconciliation and cannot be deleted.';
    END IF;
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
