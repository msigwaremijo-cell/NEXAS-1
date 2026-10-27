<?php

namespace App\Domain\Accounting\Exceptions;

/**
 * A document line cannot be priced: the tax code it names has no rate in force
 * on the document date, or the tax it produces has nowhere to post.
 *
 * These are thrown rather than defaulted to zero, because a silent zero rate
 * would understate the VAT on a filed invoice.
 */
final class DocumentLineException extends AccountingException
{
    public static function unknownTaxCode(int $taxCodeId): self
    {
        return new self(sprintf(
            'Tax code %d is not a live tax code of this company.',
            $taxCodeId
        ));
    }

    public static function noRateInForce(string $code, string $date): self
    {
        return new self(sprintf(
            'Tax code %s has no rate in force on %s, so a line cannot be priced with it.',
            $code,
            $date
        ));
    }

    public static function taxAccountMissing(string $code): self
    {
        return new self(sprintf(
            'Tax code %s carries a rate but names no ledger account to post the tax to.',
            $code
        ));
    }
}
