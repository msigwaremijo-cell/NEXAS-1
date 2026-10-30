<?php

namespace App\Domain\Reporting\Enums;

/**
 * Whether a cost centre may still be named on a new plan line.
 *
 * Nothing is deleted. A cost centre that has been retired keeps the plan lines
 * that name it, because a comparison against what actually happened still has
 * to be able to say which part of the company the plan was for. What the status
 * decides is whether it may be named on a line written from now on.
 */
enum CostCenterStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function acceptsNewLines(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
