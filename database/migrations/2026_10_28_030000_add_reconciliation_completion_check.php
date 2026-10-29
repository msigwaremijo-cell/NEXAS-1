<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The row-scoped half of the reconciliation lock.
 *
 * Two rules live here, both of which MySQL can decide from a single row:
 *
 *   reconciliations        reconciled_at and reconciled_by_user_id are either
 *                          both set or both null. A completion stamp that names
 *                          no one, or names someone without a time, is a claim
 *                          nobody can be held to.
 *   bank_statement_lines   an ignored line must carry a reason. Ignoring is the
 *                          deliberate exception that takes a movement out of
 *                          the reconciliation, and an exception with no written
 *                          justification is indistinguishable from an oversight.
 *
 * The foreign key on reconciled_by_user_id is narrowed from ON DELETE SET NULL
 * to ON DELETE RESTRICT at the same time. InnoDB applies a referential action
 * as an ordinary row write, so SET NULL would blank the user on a row whose
 * reconciled_at is still set and MySQL would then reject its own action against
 * the CHECK above. A completed reconciliation is a permanent record of who
 * signed it off, so the stamp must not be removable — and the neighbouring
 * foreign keys on this table are all RESTRICT for the same reason.
 *
 * Neither rule can see another row or another table, so both are CHECK
 * constraints rather than triggers. The rules that span rows — freezing a
 * completed reconciliation and the statement lines it matched — are in the
 * follow-up migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `reconciliations` DROP FOREIGN KEY `reconciliations_reconciled_by_user_id_foreign`');

        DB::statement(
            'ALTER TABLE `reconciliations` ADD CONSTRAINT `reconciliations_reconciled_by_user_id_foreign`'
            .' FOREIGN KEY (`reconciled_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT'
        );

        DB::statement(
            'ALTER TABLE `reconciliations` ADD CONSTRAINT `chk_reconciliations_completion_paired`'
            .' CHECK ((`reconciled_at` IS NULL AND `reconciled_by_user_id` IS NULL)'
            .' OR (`reconciled_at` IS NOT NULL AND `reconciled_by_user_id` IS NOT NULL))'
        );

        DB::statement('ALTER TABLE `bank_statement_lines` ADD COLUMN `ignored_reason` VARCHAR(255) NULL AFTER `status`');

        DB::statement(
            'ALTER TABLE `bank_statement_lines` ADD CONSTRAINT `chk_bank_statement_lines_ignored_reason`'
            ." CHECK (`status` <> 'ignored' OR (`ignored_reason` IS NOT NULL AND `ignored_reason` <> ''))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `bank_statement_lines` DROP CHECK `chk_bank_statement_lines_ignored_reason`');
        DB::statement('ALTER TABLE `bank_statement_lines` DROP COLUMN `ignored_reason`');

        DB::statement('ALTER TABLE `reconciliations` DROP CHECK `chk_reconciliations_completion_paired`');
        DB::statement('ALTER TABLE `reconciliations` DROP FOREIGN KEY `reconciliations_reconciled_by_user_id_foreign`');

        DB::statement(
            'ALTER TABLE `reconciliations` ADD CONSTRAINT `reconciliations_reconciled_by_user_id_foreign`'
            .' FOREIGN KEY (`reconciled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL'
        );
    }
};
