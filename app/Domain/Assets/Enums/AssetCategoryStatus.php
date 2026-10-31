<?php

namespace App\Domain\Assets\Enums;

/**
 * Whether a category may still be named by a new asset.
 *
 * Nothing is deleted. A category that has been retired keeps the assets already
 * written against it, because the register still has to be able to say what
 * kind of thing each one is. What the status decides is whether it may be named
 * by an asset entered from now on.
 */
enum AssetCategoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function acceptsNewAssets(): bool
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
