<?php

namespace App\Domain\Reporting\Enums;

/**
 * The three stances a saved forecast scenario can take.
 *
 * The type is a label for a reader deciding which way a set of assumptions
 * leans — it changes nothing by itself. The figures that move a projection are
 * the revenue growth, the expense growth and the account adjustments, and those
 * are stored on the version. The type is only a name for the direction those
 * figures point, so a base scenario whose growth is zero is honestly a base and
 * not a promise that it will match the plan.
 */
enum ForecastScenarioType: string
{
    case Base = 'base';

    case Optimistic = 'optimistic';

    case Pessimistic = 'pessimistic';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Base',
            self::Optimistic => 'Optimistic',
            self::Pessimistic => 'Pessimistic',
        };
    }
}
