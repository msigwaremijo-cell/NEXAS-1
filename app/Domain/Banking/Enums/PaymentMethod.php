<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `payments.method` column comment
 * ('bank|cash|mobile_money|cheque').
 */
enum PaymentMethod: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Cheque = 'cheque';

    /**
     * True when the method settles through a real bank account rather than a
     * cash till, which is what the statement importer reconciles against.
     */
    public function isBanked(): bool
    {
        return $this === self::Bank || $this === self::Cheque;
    }

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank transfer',
            self::Cash => 'Cash',
            self::MobileMoney => 'Mobile money',
            self::Cheque => 'Cheque',
        };
    }
}
