<?php

namespace App\Domain\Reporting\Services;

use App\Models\FiscalPeriod;
use Carbon\CarbonImmutable;

/**
 * Works out the window a comparative statement should be read against.
 *
 * A comparative report is only meaningful when both columns cover the same
 * length of time, so two rules are deliberately narrow. A range report compares
 * against the window of equal length immediately before it, which is the
 * "previous period" a reader assumes. A standing report — a balance sheet, which
 * is a figure as at a date rather than a movement over a range — compares
 * against the end of the fiscal period before the one the date falls in, because
 * a balance sheet has no window of its own to halve.
 *
 * Dates are handled as immutable values so a comparison window cannot be
 * mutated by a caller after it has been built.
 */
final class PeriodComparison
{
    /**
     * The window of equal length immediately before the given one.
     *
     * Both ends are inclusive, so a window of the first and the thirty-first of
     * March is thirty-one days and compares against the whole of February —
     * except that February is shorter, so the earlier window is the thirty-one
     * days that end the day before March starts. Equal length is the point: two
     * windows of different lengths are not comparable.
     *
     * @return array{0: string, 1: string}
     */
    public function precedingWindow(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        $length = (int) $start->diffInDays($end) + 1;
        $priorEnd = $start->subDay();

        return [
            $priorEnd->subDays($length - 1)->toDateString(),
            $priorEnd->toDateString(),
        ];
    }

    /**
     * The end of the latest fiscal period that closed before the given date, or
     * null when there is no earlier period to compare against.
     *
     * Read from the company's own calendar rather than by stepping back a fixed
     * number of months, because the calendar, not the caller, decides where a
     * period ends.
     */
    public function previousPeriodEnd(int $companyId, string $asOf): ?string
    {
        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('ends_on', '<', $asOf)
            ->orderByDesc('ends_on')
            ->first();

        return $period?->ends_on?->toDateString();
    }
}
