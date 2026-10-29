<?php

namespace App\Domain\Inventory\Enums;

/**
 * What kind of thing a catalogue item is.
 *
 * Only goods hold stock. A service is sold like anything else but has nothing
 * to count, so a stock movement naming a service is a mistake rather than an
 * empty ledger entry waiting to be written.
 */
enum ProductType: string
{
    case Goods = 'goods';

    case Service = 'service';

    public function holdsStock(): bool
    {
        return $this === self::Goods;
    }

    public function label(): string
    {
        return match ($this) {
            self::Goods => 'Goods',
            self::Service => 'Service',
        };
    }
}
