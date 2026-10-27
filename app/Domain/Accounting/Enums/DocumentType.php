<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `document_sequences.document_type` column comment
 * ('journal|invoice|bill|payment|credit_note').
 *
 * Each type gets its own gapless counter per company and fiscal year.
 */
enum DocumentType: string
{
    case Journal = 'journal';
    case Invoice = 'invoice';
    case Bill = 'bill';
    case Payment = 'payment';
    case CreditNote = 'credit_note';

    public function label(): string
    {
        return match ($this) {
            self::Journal => 'Journal',
            self::Invoice => 'Invoice',
            self::Bill => 'Bill',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
        };
    }
}
