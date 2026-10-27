<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `accounts.account_type` column comment
 * ('asset|liability|equity|revenue|expense').
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * The side a positive balance sits on. Assets and expenses are debits;
     * liabilities, equity and revenue are credits.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normalBalance() === NormalBalance::Debit;
    }

    /**
     * Revenue and expense accounts are closed into equity at year end;
     * the other three are permanent balance sheet accounts.
     */
    public function isTemporary(): bool
    {
        return $this === self::Revenue || $this === self::Expense;
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Asset',
            self::Liability => 'Liability',
            self::Equity => 'Equity',
            self::Revenue => 'Revenue',
            self::Expense => 'Expense',
        };
    }
}
