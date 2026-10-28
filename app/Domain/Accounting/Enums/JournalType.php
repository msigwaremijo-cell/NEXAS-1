<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `journals.journal_type` column comment
 * ('general|sales|purchase|receipt|payment|credit_note|opening|closing|reversal|transfer').
 */
enum JournalType: string
{
    case General = 'general';
    case Sales = 'sales';
    case Purchase = 'purchase';
    case Receipt = 'receipt';
    case Payment = 'payment';
    case CreditNote = 'credit_note';
    case Opening = 'opening';
    case Closing = 'closing';
    case Reversal = 'reversal';

    /**
     * Money moved between two of the company's own accounts. It is not a
     * receipt or a payment because no third party is involved, and it is kept
     * apart from a general journal because a cash flow statement has to be able
     * to leave it out: moving money between your own accounts is not a cash
     * flow, and counting both sides would invent an inflow and an outflow that
     * never happened.
     */
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General journal',
            self::Sales => 'Sales journal',
            self::Purchase => 'Purchase journal',
            self::Receipt => 'Receipt',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
            self::Opening => 'Opening balances',
            self::Closing => 'Closing entries',
            self::Reversal => 'Reversal',
            self::Transfer => 'Transfer',
        };
    }
}
