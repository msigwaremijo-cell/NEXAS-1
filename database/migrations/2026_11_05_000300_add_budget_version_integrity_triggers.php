<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Immutability and tenant integrity for budget version history.
 *
 * A budget plan is not the same kind of record a forecast scenario is. A
 * scenario version is written once and never touched; a budget version has a
 * life — it is drafted, edited freely while it is a draft, raised for approval
 * and, once approved, frozen. So the rules here are conditional on the
 * version's standing rather than absolute:
 *
 *   budget_versions        a version may not disagree with the company of the
 *                          fiscal year it belongs to, so a bad insert cannot
 *                          file a plan under another tenant.
 *
 *   budget_version_lines   a line belongs to one company, one version and one
 *                          fiscal period of that same company, and it may only
 *                          be written while its version is still a draft. A
 *                          line's UPDATE or DELETE is refused unless the version
 *                          it sits in is a draft too. Once a version is raised
 *                          or approved its amounts are frozen: a change is the
 *                          next version, never an edit of a signed-off one.
 *
 * The guards read their parents in the same way the forecast version triggers
 * do, so the rules are enforced by the database rather than trusted to the
 * service that writes them. Nothing here touches the ledger.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $triggers = [
        ['budget_versions', 'trg_budget_versions_before_insert', 'BEFORE INSERT', <<<'SQL'
BEGIN
    DECLARE v_company BIGINT UNSIGNED;

    SET v_company = (SELECT `company_id` FROM `fiscal_years` WHERE `id` = NEW.`fiscal_year_id`);

    IF v_company IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The fiscal year for this budget version does not exist.';
    END IF;

    IF NOT (NEW.`company_id` <=> v_company) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A budget version company_id must match its fiscal year.';
    END IF;
END
SQL],

        ['budget_version_lines', 'trg_budget_version_lines_before_insert', 'BEFORE INSERT', <<<'SQL'
BEGIN
    DECLARE v_company BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(20);
    DECLARE v_period_company BIGINT UNSIGNED;

    SELECT `company_id`, `status` INTO v_company, v_status
        FROM `budget_versions` WHERE `id` = NEW.`budget_version_id`;

    IF v_company IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The budget version for this line does not exist.';
    END IF;

    IF NOT (NEW.`company_id` <=> v_company) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A budget version line company_id must match its version.';
    END IF;

    SET v_period_company = (SELECT `company_id` FROM `fiscal_periods` WHERE `id` = NEW.`fiscal_period_id`);

    IF NOT (NEW.`company_id` <=> v_period_company) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A budget version line must belong to the company of its fiscal period.';
    END IF;

    IF v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft budget version may be edited. Raise the next version instead.';
    END IF;
END
SQL],

        ['budget_version_lines', 'trg_budget_version_lines_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    DECLARE v_status VARCHAR(20);

    SELECT `status` INTO v_status FROM `budget_versions` WHERE `id` = OLD.`budget_version_id`;

    IF v_status IS NULL OR v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A budget version that has been raised is immutable. Raise the next version instead.';
    END IF;
END
SQL],

        ['budget_version_lines', 'trg_budget_version_lines_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    DECLARE v_status VARCHAR(20);

    SELECT `status` INTO v_status FROM `budget_versions` WHERE `id` = OLD.`budget_version_id`;

    IF v_status IS NULL OR v_status <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A budget version that has been raised is immutable. Raise the next version instead.';
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
