<?php

namespace App\Domain\Reporting\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Models\FiscalYear;

/**
 * A forecast asked for where the calendar cannot answer.
 *
 * A forecast is projected onto the periods the company has actually opened, so
 * it needs three things to exist: a calendar with periods in it, a period the
 * given date falls in, and an open year around that period. When one of them is
 * missing nothing about the request is malformed and nothing the caller changes
 * in it would help — the books simply do not hold the shape being asked about —
 * so this is a refusal against the state of the books rather than a validation
 * error, and it is reported as a conflict rather than a bad request.
 */
final class ForecastUnavailableException extends AccountingException
{
    public static function noCalendar(int $companyId): self
    {
        return new self(sprintf(
            'Company %d has no fiscal periods, so there is no calendar to forecast against. '
            .'Open a fiscal year first.',
            $companyId
        ));
    }

    public static function noCurrentPeriod(int $companyId, string $asOf): self
    {
        return new self(sprintf(
            'Company %d has no fiscal period covering %s, so there is no current period to '
            .'forecast from. A forecast runs across the periods that already exist.',
            $companyId,
            $asOf
        ));
    }

    public static function yearNotOpen(FiscalYear $year): self
    {
        return new self(sprintf(
            'Fiscal year %s is %s, so there is no open year to forecast from. '
            .'Only an open year is projected.',
            $year->name,
            $year->status->value
        ));
    }
}
