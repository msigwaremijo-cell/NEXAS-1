<?php

namespace App\Domain\Accounting\Exceptions;

use App\Models\FiscalPeriod;

/**
 * A posting date could not be mapped to a period, or the period it maps to is
 * not accepting postings. The database trigger enforces the same rule; this
 * exists so the caller gets a sentence instead of SQLSTATE 45000.
 */
final class FiscalPeriodNotOpenException extends AccountingException
{
    public static function missing(int $companyId, string $date): self
    {
        return new self(sprintf(
            'No fiscal period of company %d contains %s, so the posting date cannot be resolved.',
            $companyId,
            $date
        ));
    }

    public static function periodClosed(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'Fiscal period %s is %s, so it cannot accept postings.',
            $period->name,
            $period->status?->value ?? 'unknown'
        ));
    }

    public static function yearClosed(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'The fiscal year that owns period %s is not open, so it cannot accept postings.',
            $period->name
        ));
    }
}
