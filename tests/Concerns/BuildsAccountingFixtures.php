<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Minimal raw-SQL fixtures for the database-level integrity tests.
 *
 * These tests deliberately bypass Eloquent: they exist to prove what MySQL
 * itself enforces through CHECK constraints and triggers, so they must be
 * able to attempt rows that the application layer would never produce.
 */
trait BuildsAccountingFixtures
{
    protected int $companyId;

    protected int $fiscalYearId;

    protected int $fiscalPeriodId;

    protected int $cashAccountId;

    protected int $revenueAccountId;

    protected int $receivableAccountId;

    protected int $payableAccountId;

    /**
     * The ledger account the bank account fixture is backed by. This is an
     * accounts row, not a bank_accounts row; the two are different things and
     * the names say which is which.
     */
    protected int $bankLedgerAccountId;

    /**
     * The bank_accounts row a receipt or payment names. Written by
     * makeBankAccountRecord().
     */
    protected int $bankAccountRecordId;

    protected int $expenseAccountId;

    protected string $postingDate = '2026-03-15';

    protected function makeFixtures(): void
    {
        $now = now();

        DB::table('currencies')->insert([
            'code' => 'TZS',
            'name' => 'Tanzanian Shilling',
            'symbol' => 'TSh',
            'decimal_places' => 2,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->companyId = DB::table('companies')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'name' => 'Nexas Test Co',
            'country_code' => 'TZ',
            'base_currency_code' => 'TZS',
            'fiscal_year_start_month' => 1,
            'timezone' => 'Africa/Dar_es_Salaam',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->fiscalYearId = DB::table('fiscal_years')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $this->companyId,
            'name' => 'FY2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->fiscalPeriodId = DB::table('fiscal_periods')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $this->companyId,
            'fiscal_year_id' => $this->fiscalYearId,
            'period_no' => 3,
            'name' => 'March 2026',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-31',
            'status' => 'open',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->cashAccountId = $this->makeAccount('1000', 'Cash on Hand', 'asset', 'debit', true);
        $this->revenueAccountId = $this->makeAccount('4000', 'Sales Revenue', 'revenue', 'credit');
    }

    /**
     * The accounts a trading document needs beyond the two makeFixtures()
     * installs: a receivable control account to bill a customer's balance into,
     * a payable control account for suppliers, a bank account to settle
     * through, and an expense account to buy into.
     *
     * Deliberately not folded into makeFixtures(): the report tests count the
     * chart of accounts, so four extra rows there would change their answer.
     */
    protected function makeTradingFixtures(): void
    {
        $this->receivableAccountId = $this->makeAccount('1100', 'Accounts Receivable', 'asset', 'debit');
        $this->payableAccountId = $this->makeAccount('2000', 'Accounts Payable', 'liability', 'credit');
        $this->bankLedgerAccountId = $this->makeAccount('1010', 'Bank Current Account', 'asset', 'debit', true);
        $this->expenseAccountId = $this->makeAccount('5000', 'Purchases', 'expense', 'debit');
    }

    /**
     * A cash or bank account of the company, backed by the 1010 ledger account
     * unless another is named. A transfer between two accounts needs both to be
     * backed by different ledger accounts, or the two sides of its journal
     * would land on the same account and cancel out.
     *
     * Deliberately not part of makeTradingFixtures(): the banking tests count
     * and page the company's bank accounts, so a row installed for every
     * trading test would change their answer. A test that names a bank account
     * asks for one.
     */
    protected function makeBankAccountRecord(
        string $name = 'Main Bank Account',
        string $status = 'active',
        ?int $ledgerAccountId = null,
    ): int {
        return $this->bankAccountRecordId = DB::table('bank_accounts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $this->companyId,
            'account_id' => $ledgerAccountId ?? $this->bankLedgerAccountId,
            'name' => $name,
            'bank_name' => 'CRDB Bank',
            'account_number' => '0150-1234567-00',
            'currency_code' => 'TZS',
            'account_type' => 'bank',
            'opening_balance' => '0',
            'status' => $status,
            'row_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeAccount(
        string $code,
        string $name,
        string $type,
        string $normalBalance,
        bool $isCash = false,
    ): int {
        return DB::table('accounts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $this->companyId,
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'depth' => 0,
            'is_postable' => true,
            'is_cash' => $isCash,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function makeJournal(string $number, string $status = 'draft'): int
    {
        return DB::table('journals')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $this->companyId,
            'fiscal_year_id' => $this->fiscalYearId,
            'fiscal_period_id' => $this->fiscalPeriodId,
            'journal_no' => $number,
            'journal_type' => 'general',
            'status' => $status,
            'posting_date' => $this->postingDate,
            'currency_code' => 'TZS',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function addLine(
        int $journalId,
        int $lineNo,
        int $accountId,
        string $debit = '0',
        string $credit = '0',
        ?string $postingDate = null,
    ): int {
        return DB::table('journal_lines')->insertGetId([
            'company_id' => $this->companyId,
            'journal_id' => $journalId,
            'line_no' => $lineNo,
            'account_id' => $accountId,
            'currency_code' => 'TZS',
            'posting_date' => $postingDate ?? $this->postingDate,
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A draft journal with one debit line and one matching credit line.
     */
    protected function balancedJournal(string $number, string $amount = '100.0000'): int
    {
        $journalId = $this->makeJournal($number);
        $this->addLine($journalId, 1, $this->cashAccountId, $amount, '0');
        $this->addLine($journalId, 2, $this->revenueAccountId, '0', $amount);

        return $journalId;
    }

    protected function postJournal(int $journalId): void
    {
        DB::table('journals')->where('id', $journalId)->update([
            'status' => 'posted',
            'posted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function appendAuditRow(string $action, ?array $before = null, ?array $after = null): int
    {
        return DB::table('audit_logs')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'company_id' => $this->companyId,
            'action' => $action,
            'auditable_type' => 'App\\Models\\Journal',
            'auditable_id' => 1,
            'ip_address' => '127.0.0.1',
            'before' => $before === null ? null : json_encode($before),
            'after' => $after === null ? null : json_encode($after),
            'created_at' => now(),
        ]);
    }
}
