<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integrity that a CHECK constraint cannot express.
 *
 * MySQL CHECK constraints are row-scoped, so the rules that need to look at
 * other rows or other tables live here as triggers:
 *
 *   journals          draft -> posted requires a balanced, non-empty journal
 *                     in an open fiscal period/year, and derives the header
 *                     totals from the lines. Posted journals are then frozen
 *                     in content, and the only permitted move is the single
 *                     posted -> reversed transition that records the link.
 *   journal_lines     may only be touched while the parent journal is a draft,
 *                     and a line may not disagree with its journal's company
 *                     or posting date.
 *   audit_logs        append-only, and the SHA-256 hash chain is computed by
 *                     the database so no code path can insert a row that does
 *                     not extend the chain correctly.
 *
 * The hash payload is the same field order that App\Support\AuditChain builds,
 * separated by ASCII unit separator (CHAR(31)), so audit:verify can recompute
 * it byte for byte.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $triggers = [
        ['journals', 'trg_journals_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    DECLARE v_lines INT DEFAULT 0;
    DECLARE v_debit DECIMAL(19,4) DEFAULT 0;
    DECLARE v_credit DECIMAL(19,4) DEFAULT 0;
    DECLARE v_period_status VARCHAR(20);
    DECLARE v_year_status VARCHAR(20);

    IF NOT (OLD.`status` = 'draft' AND NEW.`status` = 'draft') THEN
        IF OLD.`status` = 'draft' AND NEW.`status` = 'posted' THEN
            IF NOT (NEW.`company_id` <=> OLD.`company_id`)
               OR NOT (NEW.`fiscal_year_id` <=> OLD.`fiscal_year_id`)
               OR NOT (NEW.`fiscal_period_id` <=> OLD.`fiscal_period_id`)
               OR NOT (NEW.`journal_no` <=> OLD.`journal_no`)
               OR NOT (NEW.`journal_type` <=> OLD.`journal_type`)
               OR NOT (NEW.`posting_date` <=> OLD.`posting_date`)
               OR NOT (NEW.`currency_code` <=> OLD.`currency_code`)
               OR NOT (NEW.`exchange_rate` <=> OLD.`exchange_rate`)
               OR NOT (NEW.`reverses_journal_id` <=> OLD.`reverses_journal_id`) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal content may not change at the moment of posting.';
            END IF;

            SELECT COUNT(*), COALESCE(SUM(`debit`), 0), COALESCE(SUM(`credit`), 0)
              INTO v_lines, v_debit, v_credit
              FROM `journal_lines` WHERE `journal_id` = NEW.`id`;

            IF v_lines < 2 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal needs at least two lines before it can be posted.';
            END IF;

            IF v_debit <= 0 OR v_credit <= 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal must have a value greater than zero before it can be posted.';
            END IF;

            IF v_debit <> v_credit THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Journal is not balanced: total debits must equal total credits.';
            END IF;

            SET v_period_status = (SELECT `status` FROM `fiscal_periods` WHERE `id` = NEW.`fiscal_period_id`);
            IF v_period_status IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The fiscal period for this journal does not exist.';
            END IF;
            IF NOT (v_period_status <=> 'open') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The fiscal period is not open, so it cannot accept postings.';
            END IF;

            SET v_year_status = (SELECT `status` FROM `fiscal_years` WHERE `id` = NEW.`fiscal_year_id`);
            IF v_year_status IS NULL OR NOT (v_year_status <=> 'open') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The fiscal year is not open, so it cannot accept postings.';
            END IF;

            SET NEW.`total_debit` = v_debit;
            SET NEW.`total_credit` = v_credit;
            SET NEW.`posted_at` = COALESCE(NEW.`posted_at`, NOW());

        ELSEIF OLD.`status` = 'posted' AND NEW.`status` = 'reversed' THEN
            IF NOT (NEW.`company_id` <=> OLD.`company_id`)
               OR NOT (NEW.`fiscal_year_id` <=> OLD.`fiscal_year_id`)
               OR NOT (NEW.`fiscal_period_id` <=> OLD.`fiscal_period_id`)
               OR NOT (NEW.`journal_no` <=> OLD.`journal_no`)
               OR NOT (NEW.`journal_type` <=> OLD.`journal_type`)
               OR NOT (NEW.`posting_date` <=> OLD.`posting_date`)
               OR NOT (NEW.`currency_code` <=> OLD.`currency_code`)
               OR NOT (NEW.`exchange_rate` <=> OLD.`exchange_rate`)
               OR NOT (NEW.`total_debit` <=> OLD.`total_debit`)
               OR NOT (NEW.`total_credit` <=> OLD.`total_credit`)
               OR NOT (NEW.`posted_at` <=> OLD.`posted_at`) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A posted journal is immutable; only its reversal link may be recorded.';
            END IF;

        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journals are immutable. Correct a posting by reversing it.';
        END IF;
    END IF;
END
SQL],

        ['journals', 'trg_journals_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    IF NOT (OLD.`status` <=> 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journals cannot be deleted. Reverse the journal instead.';
    END IF;
END
SQL],

        ['journal_lines', 'trg_journal_lines_before_insert', 'BEFORE INSERT', <<<'SQL'
BEGIN
    DECLARE v_status VARCHAR(20);
    DECLARE v_company BIGINT UNSIGNED;
    DECLARE v_date DATE;

    SET v_status  = (SELECT `status`       FROM `journals` WHERE `id` = NEW.`journal_id`);
    SET v_company = (SELECT `company_id`   FROM `journals` WHERE `id` = NEW.`journal_id`);
    SET v_date    = (SELECT `posting_date` FROM `journals` WHERE `id` = NEW.`journal_id`);

    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The journal for this line does not exist.';
    END IF;

    IF NOT (v_status <=> 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Lines may only be added while the journal is a draft.';
    END IF;

    IF NOT (NEW.`company_id` <=> v_company) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal line company_id must match its journal.';
    END IF;

    IF NOT (NEW.`posting_date` <=> v_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal line posting_date must match its journal.';
    END IF;
END
SQL],

        ['journal_lines', 'trg_journal_lines_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    DECLARE v_status VARCHAR(20);

    SET v_status = (SELECT `status` FROM `journals` WHERE `id` = OLD.`journal_id`);

    IF NOT (v_status <=> 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The lines of a posted journal are immutable.';
    END IF;

    IF NOT (NEW.`journal_id` <=> OLD.`journal_id`) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A journal line cannot be moved to a different journal.';
    END IF;
END
SQL],

        ['journal_lines', 'trg_journal_lines_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    DECLARE v_status VARCHAR(20);

    SET v_status = (SELECT `status` FROM `journals` WHERE `id` = OLD.`journal_id`);

    IF NOT (v_status <=> 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The lines of a posted journal cannot be deleted.';
    END IF;
END
SQL],

        ['audit_logs', 'trg_audit_logs_before_insert', 'BEFORE INSERT', <<<'SQL'
BEGIN
    DECLARE v_prev CHAR(64);

    SELECT `last_hash` INTO v_prev FROM `audit_chain_head` WHERE `id` = 1 FOR UPDATE;

    SET NEW.`previous_hash` = v_prev;

    SET NEW.`hash` = SHA2(CONCAT_WS(CHAR(31),
        COALESCE(v_prev, ''),
        COALESCE(CAST(NEW.`company_id` AS CHAR), ''),
        COALESCE(CAST(NEW.`user_id` AS CHAR), ''),
        COALESCE(NEW.`action`, ''),
        COALESCE(NEW.`auditable_type`, ''),
        COALESCE(CAST(NEW.`auditable_id` AS CHAR), ''),
        COALESCE(NEW.`ip_address`, ''),
        COALESCE(NEW.`user_agent`, ''),
        COALESCE(CAST(NEW.`before` AS CHAR), ''),
        COALESCE(CAST(NEW.`after` AS CHAR), ''),
        COALESCE(CAST(NEW.`meta` AS CHAR), '')
    ), 256);
END
SQL],

        ['audit_logs', 'trg_audit_logs_after_insert', 'AFTER INSERT', <<<'SQL'
BEGIN
    UPDATE `audit_chain_head`
       SET `last_hash` = NEW.`hash`,
           `last_id` = NEW.`id`,
           `chain_length` = `chain_length` + 1,
           `updated_at` = NOW()
     WHERE `id` = 1;
END
SQL],

        ['audit_logs', 'trg_audit_logs_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only and cannot be updated.';
END
SQL],

        ['audit_logs', 'trg_audit_logs_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only and cannot be deleted.';
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
