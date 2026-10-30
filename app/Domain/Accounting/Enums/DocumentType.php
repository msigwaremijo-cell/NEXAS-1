<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `document_sequences.document_type` column comment
 * ('journal|invoice|bill|payment|credit_note'), which has since grown a
 * `debit_note` case of its own. The column is a plain VARCHAR, so the enum is
 * the authority on the set.
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
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return match ($this) {
            self::Journal => 'Journal',
            self::Invoice => 'Invoice',
            self::Bill => 'Bill',
            self::Payment => 'Payment',
            self::CreditNote => 'Credit note',
            self::DebitNote => 'Debit note',
        };
    }
}
