<?php

namespace App\Domain\Assets\Services;

use App\Models\Asset;
use App\Models\FiscalPeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Straight-line depreciation, month by month, from the in-service date.
 *
 * The method is deliberately the simplest one there is: the cost less the
 * salvage value is spread evenly over the asset's life in months, and the asset
 * is charged for each whole month of that life it was in service at the end of.
 * An asset placed in service on the 15th is charged a full month for that
 * month, which is the ordinary convention for a month-by-month schedule.
 *
 * Two rules keep the arithmetic exact rather than approximate:
 *
 *   - the asset is never depreciated below its salvage value, so a charge is
 *     capped at what is left of the depreciable amount;
 *   - the period in which the life runs out takes the remainder rather than a
 *     whole monthly amount, so the total charged over the life equals the cost
 *     less the salvage value exactly. Without that catch-up a life that does
 *     not divide evenly would leave a fraction of a shilling un-depreciated for
 *     ever.
 *
 * This is the accounting method and nothing else. It is not a statement about
 * Tanzanian tax depreciation: the Income Tax Act sets its own rates and its own
 * method, which may differ from this in both, so the life and the rate used
 * here need confirmation from a qualified accountant before the figures are
 * relied on. The same sentence is returned with the schedule so a reader of the
 * API is told rather than left to assume.
 */
final class DepreciationSchedule
{
    public const METHOD = 'straight_line';

    public const METHOD_NOTE = 'Straight-line, month by month from the in-service date. This is '
        .'the accounting method only: it is not a claim about Tanzanian tax depreciation, which is '
        .'set by the Income Tax Act and may differ in both rate and method. The useful life used '
        .'here needs confirmation from a qualified accountant before the figures are relied on.';

    /**
     * The charge for one month of the asset's life.
     *
     * Quantised to the money scale here rather than kept at full precision,
     * because the ledger stores four decimals: the monthly figure a reader is
     * shown is the figure the journal is made of.
     */
    public function monthlyAmount(Asset $asset): Money
    {
        return Money::of($asset->acquisition_cost)
            ->minus(Money::of($asset->salvage_value))
            ->dividedBy(max(1, (int) $asset->useful_life_months));
    }

    /**
     * How many whole months of the period the asset was in service at the end
     * of: the calendar month ends that fall inside the period and on or after
     * the in-service date.
     *
     * A monthly period therefore yields 0 or 1, and a longer one yields one per
     * month it spans — which is what lets a quarter be depreciated in a single
     * run without restating the arithmetic.
     */
    public function monthsInPeriod(Asset $asset, FiscalPeriod $period): int
    {
        if ($asset->in_service_date === null) {
            return 0;
        }

        // The period's own dates are days, so its end is the end of its last
        // day rather than the midnight it begins on: a month end of 23:59:59
        // is inside a period that ends on the 31st, and comparing it against
        // midnight of the 31st would say otherwise.
        $start = CarbonImmutable::parse($period->starts_on->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($period->ends_on->toDateString())->endOfDay();
        $inService = CarbonImmutable::parse($asset->in_service_date->toDateString())->startOfDay();

        $months = 0;

        for ($cursor = $start->startOfMonth(); $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addMonthNoOverflow()->startOfMonth()) {
            $monthEnd = $cursor->endOfMonth();

            if ($monthEnd->greaterThanOrEqualTo($inService)
                && $monthEnd->greaterThanOrEqualTo($start)
                && $monthEnd->lessThanOrEqualTo($end)) {
                $months++;
            }
        }

        return $months;
    }

    /**
     * How many months of the asset's life have run by the end of `through`.
     *
     * This is what says whether a period is the last one of the life: the count
     * reaching the life in months is what triggers the catch-up charge.
     */
    public function elapsedMonths(string $inServiceDate, string $through): int
    {
        $inService = CarbonImmutable::parse($inServiceDate)->startOfDay();
        // The day `through` is named, taken to its end: a month end falling on
        // that day has run by it.
        $end = CarbonImmutable::parse($through)->endOfDay();

        if ($end->lessThan($inService)) {
            return 0;
        }

        $months = 0;

        for ($cursor = $inService->startOfMonth(); $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addMonthNoOverflow()->startOfMonth()) {
            $monthEnd = $cursor->endOfMonth();

            if ($monthEnd->greaterThanOrEqualTo($inService) && $monthEnd->lessThanOrEqualTo($end)) {
                $months++;
            }
        }

        return $months;
    }

    /**
     * The charge for one period, which is zero when there is nothing to charge.
     *
     * A period with nothing to charge is a real answer, not a fault: an asset
     * outside its life, or one already depreciated down to its salvage value,
     * contributes nothing. The run decides what to do with a period where every
     * asset answers zero.
     */
    public function charge(Asset $asset, FiscalPeriod $period): Money
    {
        if ($asset->in_service_date === null || ! $asset->status->isDepreciable()) {
            return Money::zero();
        }

        $remaining = $asset->depreciableRemaining();

        if (! $remaining->isPositive()) {
            return Money::zero();
        }

        $months = $this->monthsInPeriod($asset, $period);

        if ($months === 0) {
            return Money::zero();
        }

        $elapsed = $this->elapsedMonths(
            $asset->in_service_date->toDateString(),
            $period->ends_on->toDateString()
        );

        // The period the life runs out in takes what is left rather than a
        // whole monthly amount, so the life lands exactly on cost less salvage.
        $charge = $elapsed >= (int) $asset->useful_life_months
            ? $remaining
            : $this->monthlyAmount($asset)->times($months);

        // The salvage floor: a charge never takes the asset below what it is
        // expected to be worth at the end of its life.
        return $charge->greaterThan($remaining) ? $remaining : $charge;
    }

    /**
     * The periods already run being the company's business, this only says
     * whether the asset itself has anything left to give.
     */
    public function isExhausted(Asset $asset): bool
    {
        return ! $asset->depreciableRemaining()->isPositive();
    }
}
