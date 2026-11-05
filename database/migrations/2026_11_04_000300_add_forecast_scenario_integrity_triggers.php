<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Immutability and tenant integrity for scenario version history.
 *
 * Two of these rules a CHECK constraint cannot express because they are not
 * row-scoped, so they live here as triggers:
 *
 *   forecast_scenario_versions  a version may not be updated or deleted — the
 *                               history is append-only, and a change of
 *                               assumption is a new version rather than an edit
 *                               of an old one. A version may also not disagree
 *                               with the company of the scenario it belongs to,
 *                               so a bad insert cannot file a version under
 *                               another tenant.
 *
 * The BEFORE INSERT guard reads the parent scenario in the same way the journal
 * line triggers read their journal, so the company check is enforced by the
 * database rather than trusted to the caller.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $triggers = [
        ['forecast_scenario_versions', 'trg_forecast_scenario_versions_before_insert', 'BEFORE INSERT', <<<'SQL'
BEGIN
    DECLARE v_company BIGINT UNSIGNED;

    SET v_company = (SELECT `company_id` FROM `forecast_scenarios` WHERE `id` = NEW.`forecast_scenario_id`);

    IF v_company IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The scenario for this version does not exist.';
    END IF;

    IF NOT (NEW.`company_id` <=> v_company) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A scenario version company_id must match its scenario.';
    END IF;
END
SQL],

        ['forecast_scenario_versions', 'trg_forecast_scenario_versions_before_update', 'BEFORE UPDATE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A scenario version is immutable. Save a new version instead of editing one.';
END
SQL],

        ['forecast_scenario_versions', 'trg_forecast_scenario_versions_before_delete', 'BEFORE DELETE', <<<'SQL'
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A scenario version cannot be deleted. The history is kept.';
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
