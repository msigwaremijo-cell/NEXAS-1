<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\InventoryCosting;

/**
 * A company asked for a costing method this build cannot value stock with.
 *
 * It is a 422 rather than a 409 because nothing about the books is in the way:
 * the policy value itself is the thing that has to change, and changing it
 * makes the same request succeed.
 */
final class UnsupportedCostingMethodException extends InventoryException
{
    public static function notImplemented(InventoryCosting $method): self
    {
        return new self(sprintf(
            'This company is set to cost stock by %s, which is not implemented. '
            .'Set the costing method to first in, first out before moving stock.',
            $method->label()
        ));
    }
}
