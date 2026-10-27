<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `journals.journal_type` column comment
 * ('general|sales|purchase|receipt|payment|credit_note|opening|closing|reversal').
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
        };
    }
}
