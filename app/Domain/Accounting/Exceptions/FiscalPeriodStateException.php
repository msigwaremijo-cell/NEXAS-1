<?php

namespace App\Domain\Accounting\Exceptions;

use App\Models\FiscalPeriod;

/**
 * The period exists but is not in the state a reopen assumed.
 *
 * Reopening a period that is already open, or one that has been locked for
 * good, is not a malformed request: the request was well formed when it was
 * sent and the calendar has simply moved on since. Nor is it something to
 * retry unchanged, so it collides with the books rather than being a 422.
 */
final class FiscalPeriodStateException extends AccountingException
{
    public static function notReopenable(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'Fiscal period %s is %s, so it cannot be reopened. Only a closed period can be reopened.',
            $period->name,
            $period->status?->value ?? 'unknown'
        ));
    }

    /**
     * The year above the period has been locked, and a locked year accepts
     * postings again only by a deliberate act on the year itself. Reopening
     * the period under it would open a door that stays shut, so it is refused
     * outright rather than half-done.
     */
    public static function yearLocked(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'The fiscal year that owns period %s is locked, so the period cannot be reopened.',
            $period->name
        ));
    }
}
