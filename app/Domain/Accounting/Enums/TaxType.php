<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `tax_codes.tax_type` column comment
 * ('vat|withholding|excise|levy|stamp').
 */
enum TaxType: string
{
    case Vat = 'vat';
    case Withholding = 'withholding';
    case Excise = 'excise';
    case Levy = 'levy';
    case Stamp = 'stamp';

    /**
     * Withholding tax is deducted from a payment rather than added to a line.
     * The line maths and the settlement maths therefore differ.
     */
    public function isDeducted(): bool
    {
        return $this === self::Withholding;
    }

    public function label(): string
    {
        return match ($this) {
            self::Vat => 'VAT',
            self::Withholding => 'Withholding tax',
            self::Excise => 'Excise duty',
            self::Levy => 'Levy',
            self::Stamp => 'Stamp duty',
        };
    }
}
