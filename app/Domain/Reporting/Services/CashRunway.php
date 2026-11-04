<?php

namespace App\Domain\Reporting\Services;

use App\Support\Money;

/**
 * How long the company's cash lasts, on the projection the page is showing.
 *
 * This is an estimate built from two things that already exist — the cash
 * position the ledger reports today, and the projected net movement of each
 * forecast month — and it says so. Nothing here is stored, nothing is posted
 * and no figure here can reach an actual report.
 *
 * The method is stated in the payload rather than left to be inferred:
 *
 *   - the opening cash is what CashFlowQuery reports as the company's money
 *     accounts hold as at the forecast date;
 *   - each forecast month's movement is that period's projected net profit,
 *     which is the operating movement and not a full cash flow — the balance
 *     sheet is not projected, so a month's receivables and payables timing is
 *     not invented;
 *   - the closing cash of a month is the opening plus every month's movement
 *     up to and including it, read cumulatively;
 *   - the average monthly movement is the mean of those monthly movements, and
 *     it is a "burn" only when it is negative;
 *   - the months of cover is the opening cash divided by that burn, and it is
 *     reported only when there is a burn to divide by.
 *
 * The three cases the caller has to survive are handled by answering them:
 * a company with no cash accounts has no position to read and is answered
 * `unknown`; a company whose projected movement is zero or positive is not
 * burning and its cash never runs out, so `months_of_cover` is null and the
 * month cash turns negative is null rather than a date that was invented; and
 * a company whose cash does go negative is given the first month it does, and
 * that month and no other.
 */
final class CashRunway
{
    /**
     * Intermediate precision for the cover ratio. Above the money scale so the
     * division is not rounded before it is read, matching App\Support\Money.
     */
    private const RATIO_SCALE = 4;

    public function __construct(private readonly CashFlowQuery $cash) {}

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    public function analyse(int $companyId, array $projection, string $threshold): array
    {
        $asOf = (string) $projection['as_of'];
        $position = $this->cash->position($companyId, $asOf);

        $hasCashAccounts = $position['accounts'] !== [];
        $opening = Money::of((string) $position['total']);
        $floor = Money::of($threshold);

        $months = [];
        $movements = [];
        $closing = $opening;
        $negativeMonth = null;
        $lowest = $opening;
        $lowestMonth = null;

        foreach ($projection['periods'] as $period) {
            if (! $period['is_forecast']) {
                continue;
            }

            $movement = Money::of((string) $period['net']['forecast']);
            $movements[] = $movement;

            // Closing cash is a reading of a position, so it is only taken when
            // there is a position to close from. A company with no cash accounts
            // still has a projected operating movement — which is what the burn
            // is — but nothing here can say what its closing cash would be, so no
            // month is given a balance and none is named as the month cash runs
            // out.
            if (! $hasCashAccounts) {
                continue;
            }

            $closing = $closing->plus($movement);
            $name = (string) $period['period']['name'];

            if ($negativeMonth === null && $closing->isNegative()) {
                $negativeMonth = $name;
            }

            if ($closing->lessThan($lowest)) {
                $lowest = $closing;
                $lowestMonth = $name;
            }

            $months[] = [
                'period' => $name,
                'movement' => $movement->toDecimal(),
                'closing_cash' => $closing->toDecimal(),
                'negative' => $closing->isNegative(),
                'below_threshold' => $closing->lessThan($floor),
            ];
        }

        $average = $movements === []
            ? Money::zero()
            : Money::sum($movements)->dividedBy((string) count($movements));

        $burning = $average->isNegative();
        $burn = $burning ? $average->absolute() : Money::zero();

        // Cover is only a question when there is a burn to divide by, cash to
        // run down and a position to read it from. A company not burning has no
        // runway to run out of, a company already at or below zero has none
        // left, and a company with no cash accounts has no position at all, so
        // each answers with a null rather than a division by zero or a figure
        // that would read as a runway that was never observed.
        $coverMonths = null;

        if ($burning && $hasCashAccounts && $opening->isPositive()) {
            $coverMonths = bcdiv($opening->toDecimal(), $burn->toDecimal(), self::RATIO_SCALE);
        }

        return [
            'as_of' => $asOf,
            'is_estimate' => true,
            'has_cash_data' => $hasCashAccounts,
            'opening_cash' => $opening->toDecimal(),
            'average_monthly_movement' => $average->toDecimal(),
            'average_monthly_burn' => $burn->toDecimal(),
            'is_burning' => $burning,
            'months_of_cover' => $coverMonths,
            'negative_month' => $negativeMonth,
            'no_negative_month' => $negativeMonth === null,
            'lowest_cash' => $lowest->toDecimal(),
            'lowest_cash_month' => $lowestMonth,
            'threshold' => $floor->toDecimal(),
            'status' => $this->status($hasCashAccounts, $opening, $floor, $lowest, $negativeMonth),
            'status_label' => $this->statusLabel($hasCashAccounts, $burning, $negativeMonth),
            'months' => $months,
            'horizon_months' => count($months),
            'note' => $this->note($hasCashAccounts, $burning, $coverMonths, $negativeMonth, count($months)),
            'assumptions' => $this->assumptions(),
        ];
    }

    private function status(
        bool $hasCashAccounts,
        Money $opening,
        Money $floor,
        Money $lowest,
        ?string $negativeMonth,
    ): string {
        if (! $hasCashAccounts) {
            return 'unknown';
        }

        if ($negativeMonth !== null || $opening->lessThan($floor)) {
            return 'critical';
        }

        if ($lowest->lessThanOrEqualTo($floor)) {
            return 'watch';
        }

        return 'healthy';
    }

    private function statusLabel(bool $hasCashAccounts, bool $burning, ?string $negativeMonth): string
    {
        if (! $hasCashAccounts) {
            return 'Cash position unknown';
        }

        if ($negativeMonth !== null) {
            return 'Cash is projected to run out';
        }

        if (! $burning) {
            return 'Cash is not depleting';
        }

        return 'Cash is depleting within the horizon';
    }

    private function note(
        bool $hasCashAccounts,
        bool $burning,
        ?string $coverMonths,
        ?string $negativeMonth,
        int $horizonMonths,
    ): string {
        if (! $hasCashAccounts) {
            return 'No cash or bank account is set up for this company, so there is no cash position to read and no runway can be '
                .'estimated. Set up a cash or bank account, then read this panel again.';
        }

        $basis = 'Opening cash is what the ledger reports for the company\'s money accounts today, and each month\'s movement is that '
            .'period\'s projected net profit read cumulatively. This is an estimate of operating cash and not a full cash flow: the '
            .'balance sheet is not projected, so the timing of receivables and payables inside a month is not modelled.';

        if ($negativeMonth !== null) {
            return $basis.' On these assumptions cash turns negative in '.$negativeMonth.', the first month it does within the '
                .$horizonMonths.'-month horizon.';
        }

        if (! $burning) {
            return $basis.' On these assumptions the company is not burning cash — its projected monthly movement is zero or positive — '
                .'so its cash does not run out within the '.$horizonMonths.'-month horizon and no month turns negative.';
        }

        return $basis.' On these assumptions the company is burning cash and has about '.$coverMonths.' months of cover, but its cash '
            .'never turns negative within the '.$horizonMonths.'-month horizon.';
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'The projection is the same one the rest of the page shows, restated under the scenario currently selected — there is no '
                .'second forecast buried here.',
            'Cash movement is the projected net profit of each forecast month. It is not a full cash flow statement: collection and '
                .'payment timing is not projected, so this is a runway of operating cash and not a bank balance to the day.',
            'The low-cash threshold is a company setting. It is a reading, not a control: nothing here moves money or blocks a payment.',
        ];
    }
}
