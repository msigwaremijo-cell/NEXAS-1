<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Exceptions\FiscalPeriodNotOpenException;
use App\Models\FiscalPeriod;

/**
 * Maps a posting date onto the fiscal period that owns it.
 *
 * The date -> period rule lives here rather than on the journal header, so a
 * caller can never point a posting at a period that does not contain its
 * posting date. The action that posts locks the period through this service,
 * which is what stops a period close and a posting from interleaving.
 */
final class FiscalCalendar
{
    /**
     * The period that contains the date, whether or not it is still open.
     * A draft may be written into a period that has since closed; only
     * posting is refused there.
     */
    public function resolve(int $companyId, string $date): FiscalPeriod
    {
        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->orderBy('period_no')
            ->first();

        if ($period === null) {
            throw FiscalPeriodNotOpenException::missing($companyId, $date);
        }

        return $period;
    }

    /**
     * Take the row lock on the period for the rest of the posting transaction,
     * then assert that both the period and its year accept postings.
     *
     * The lock is what makes "check then post" safe: no other transaction can
     * close the period between the check and the update.
     */
    public function lockOpen(FiscalPeriod $period): FiscalPeriod
    {
        $locked = FiscalPeriod::query()
            ->whereKey($period->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $locked->status->acceptsPostings()) {
            throw FiscalPeriodNotOpenException::periodClosed($locked);
        }

        $year = $locked->fiscalYear;

        if ($year === null || ! $year->status->acceptsPostings()) {
            throw FiscalPeriodNotOpenException::yearClosed($locked);
        }

        return $locked;
    }
}
