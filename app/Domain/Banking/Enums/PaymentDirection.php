<?php

namespace App\Domain\Banking\Enums;

/**
 * Mirrors the `payments.direction` column comment ('receipt|cash_payment').
 *
 * A receipt brings money in (debit bank/cash, credit receivables); a cash
 * payment sends money out (debit payables/expense, credit bank/cash).
 */
enum PaymentDirection: string
{
    case Receipt = 'receipt';
    case CashPayment = 'cash_payment';

    public function isInflow(): bool
    {
        return $this === self::Receipt;
    }

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Receipt',
            self::CashPayment => 'Cash payment',
        };
    }
}
