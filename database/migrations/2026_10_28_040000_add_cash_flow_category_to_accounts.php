<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The activity each account belongs to on the cash flow statement.
 *
 * The column is nullable and stays null on every row this migration touches.
 * That is the honest starting state: nobody has classified the chart yet, and
 * the statement says so by reporting those movements as unclassified rather
 * than guessing that a movement is operating. The standard chart is given its
 * answers by ChartOfAccountsSeeder, which is where a default belongs — it is a
 * decision about a particular chart of accounts, not a rule of the schema.
 *
 * The CHECK constraint is the schema's half of the bargain: whatever the
 * application writes, MySQL will not store a category the report cannot
 * classify. It is a list rather than an ENUM column because the categories are
 * a reporting concern that will grow (a fourth activity is a business decision,
 * not a migration), and widening a CHECK is a smaller change than altering an
 * ENUM's value list.
 *
 * It cannot be expressed as a foreign key: there is no table of categories and
 * inventing one to hold four words would be a table maintained for the sake of
 * a lookup nobody performs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `accounts` ADD COLUMN `cash_flow_category` VARCHAR(20) NULL AFTER `is_bank`');

        DB::statement(
            'ALTER TABLE `accounts` ADD CONSTRAINT `chk_accounts_cash_flow_category`'
            ." CHECK (`cash_flow_category` IS NULL OR `cash_flow_category` IN"
            ." ('operating','investing','financing','internal_transfer'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `accounts` DROP CHECK `chk_accounts_cash_flow_category`');
        DB::statement('ALTER TABLE `accounts` DROP COLUMN `cash_flow_category`');
    }
};
