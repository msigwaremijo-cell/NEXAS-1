<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `tax_codes.applies_to` column comment ('sales|purchases|both').
 */
enum TaxAppliesTo: string
{
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Both = 'both';

    public function appliesToSales(): bool
    {
        return $this === self::Sales || $this === self::Both;
    }

    public function appliesToPurchases(): bool
    {
        return $this === self::Purchases || $this === self::Both;
    }

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Purchases => 'Purchases',
            self::Both => 'Sales and purchases',
        };
    }
}
