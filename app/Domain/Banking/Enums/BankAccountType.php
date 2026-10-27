<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `bank_accounts.account_type` column comment
 * ('bank|cash|mobile_money'). Every value is a ledger-backed money account;
 * none of them implies a live connection to a real bank.
 */
enum BankAccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank account',
            self::Cash => 'Cash account',
            self::MobileMoney => 'Mobile money wallet',
        };
    }
}
