<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-scoped integrity rules, enforced by MySQL 8.4 CHECK constraints.
 *
 * Note the deliberate omission: SUM(debit) = SUM(credit) across a journal's
 * lines is NOT a CHECK, because a CHECK can only see the row being written.
 * That rule is enforced by a trigger on the draft -> posted transition, in the
 * follow-up migration.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $checks = [
        ['journal_lines', 'chk_journal_lines_debit_non_negative', 'debit >= 0'],
        ['journal_lines', 'chk_journal_lines_credit_non_negative', 'credit >= 0'],
        ['journal_lines', 'chk_journal_lines_debit_xor_credit', '(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)'],
        ['journal_lines', 'chk_journal_lines_base_non_negative', 'base_debit >= 0 AND base_credit >= 0'],
        ['journal_lines', 'chk_journal_lines_line_no_positive', 'line_no > 0'],

        ['journals', 'chk_journals_totals_non_negative', 'total_debit >= 0 AND total_credit >= 0'],
        ['journals', 'chk_journals_exchange_rate_positive', 'exchange_rate > 0'],

        ['journal_lines', 'chk_journal_lines_exchange_rate_positive', 'exchange_rate > 0'],

        ['fiscal_years', 'chk_fiscal_years_range', 'ends_on >= starts_on'],
        ['fiscal_periods', 'chk_fiscal_periods_range', 'ends_on >= starts_on'],
        ['fiscal_periods', 'chk_fiscal_periods_number', 'period_no BETWEEN 1 AND 12'],

        ['exchange_rates', 'chk_exchange_rates_rate_positive', 'rate > 0'],
        ['tax_rates', 'chk_tax_rates_rate_range', 'rate >= 0 AND rate <= 1'],

        ['companies', 'chk_companies_fiscal_start_month', 'fiscal_year_start_month BETWEEN 1 AND 12'],
        ['accounts', 'chk_accounts_depth_non_negative', 'depth >= 0'],

        ['customers', 'chk_customers_credit_limit_non_negative', 'credit_limit >= 0'],
        ['customers', 'chk_customers_payment_terms_non_negative', 'payment_terms_days >= 0'],
        ['suppliers', 'chk_suppliers_credit_limit_non_negative', 'credit_limit >= 0'],
        ['suppliers', 'chk_suppliers_payment_terms_non_negative', 'payment_terms_days >= 0'],

        ['products', 'chk_products_amounts_non_negative', 'unit_cost >= 0 AND sale_price >= 0'],

        ['invoices', 'chk_invoices_amounts_non_negative', 'subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND total >= 0 AND amount_paid >= 0'],
        ['invoices', 'chk_invoices_exchange_rate_positive', 'exchange_rate > 0'],
        ['bills', 'chk_bills_amounts_non_negative', 'subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND total >= 0 AND amount_paid >= 0'],
        ['bills', 'chk_bills_exchange_rate_positive', 'exchange_rate > 0'],

        ['invoice_lines', 'chk_invoice_lines_amounts_non_negative', 'quantity >= 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0'],
        ['invoice_lines', 'chk_invoice_lines_discount_rate_range', 'discount_rate >= 0 AND discount_rate <= 1'],
        ['invoice_lines', 'chk_invoice_lines_tax_rate_range', 'tax_rate >= 0 AND tax_rate <= 1'],
        ['bill_lines', 'chk_bill_lines_amounts_non_negative', 'quantity >= 0 AND unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0'],
        ['bill_lines', 'chk_bill_lines_discount_rate_range', 'discount_rate >= 0 AND discount_rate <= 1'],
        ['bill_lines', 'chk_bill_lines_tax_rate_range', 'tax_rate >= 0 AND tax_rate <= 1'],

        ['payments', 'chk_payments_amount_positive', 'amount > 0'],
        ['payments', 'chk_payments_allocation_split', 'allocated_amount >= 0 AND unapplied_amount >= 0 AND allocated_amount + unapplied_amount <= amount'],
        ['payments', 'chk_payments_exchange_rate_positive', 'exchange_rate > 0'],
        ['payment_allocations', 'chk_payment_allocations_amount_positive', 'amount > 0'],

        ['stock_movements', 'chk_stock_movements_quantity_non_zero', 'quantity <> 0'],
        ['stock_layers', 'chk_stock_layers_remaining_non_negative', 'quantity_remaining >= 0'],
        ['stock_layers', 'chk_stock_layers_received_positive', 'quantity_received > 0'],

        ['document_sequences', 'chk_document_sequences_next_number', 'next_number >= 1'],
        ['document_sequences', 'chk_document_sequences_padding', 'padding BETWEEN 1 AND 12'],

        ['ledger_balances', 'chk_ledger_balances_non_negative', 'opening_debit >= 0 AND opening_credit >= 0 AND period_debit >= 0 AND period_credit >= 0 AND closing_debit >= 0 AND closing_credit >= 0'],

        ['attachments', 'chk_attachments_size_non_negative', 'size_bytes >= 0'],
    ];

    public function up(): void
    {
        foreach ($this->checks as [$table, $name, $expression]) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->checks) as [$table, $name]) {
            DB::statement("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
        }
    }
};
