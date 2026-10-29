<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Warehouse;

/**
 * The movement or the valuation cannot be written as asked.
 *
 * Everything refused here is a collision with what stock currently records
 * rather than a flaw in the request: moving more than is on hand, reversing a
 * movement that is already reversed, or trying to value an issue when the
 * costing method has been locked by the movements already posted. Resending
 * the same request unchanged will produce the same answer, which is why the
 * HTTP layer answers these with a 409 rather than a 422.
 */
final class StockStateException extends InventoryException
{
    public static function nonPositiveQuantity(string $context): self
    {
        return new self(sprintf(
            'A stock %s must move a quantity greater than zero.',
            $context
        ));
    }

    public static function nonPositiveCost(): self
    {
        return new self('A stock cost must not be negative.');
    }

    /**
     * Stock that is going up in the count has to be worth something, and only
     * the caller knows what: the layers say what the stock on hand cost, and
     * they cannot price units that are not on hand yet.
     */
    public static function adjustmentCostRequired(): self
    {
        return new self('An adjustment that adds stock must name the unit cost those units are brought in at.');
    }

    public static function unknownMovement(int $movementId): self
    {
        return new self(sprintf(
            'No stock movement with id %d belongs to this company, so there is nothing to reverse.',
            $movementId
        ));
    }

    public static function adjustmentToZero(): self
    {
        return new self('A stock adjustment must change the quantity on hand, so it cannot be for zero.');
    }

    public static function unknownProduct(int $productId): self
    {
        return new self(sprintf(
            'No product with id %d belongs to this company, so there is nothing to move.',
            $productId
        ));
    }

    public static function unknownWarehouse(int $warehouseId): self
    {
        return new self(sprintf(
            'No warehouse with id %d belongs to this company, so there is nowhere to move the stock.',
            $warehouseId
        ));
    }

    public static function productDoesNotHoldStock(Product $product): self
    {
        return new self(sprintf(
            'Product %s is a %s, so it holds no stock to move.',
            $product->sku,
            $product->product_type->label()
        ));
    }

    public static function warehouseNotOpen(Warehouse $warehouse): self
    {
        return new self(sprintf(
            'Warehouse %s is %s, so it cannot take new stock movements.',
            $warehouse->code,
            $warehouse->status->label()
        ));
    }

    public static function sameWarehouse(int $warehouseId): self
    {
        return new self(sprintf(
            'Warehouse %d cannot be transferred to itself, so no stock would move.',
            $warehouseId
        ));
    }

    /**
     * The policy that forbids stock going negative has refused the movement.
     *
     * @param  string  $quantity  The shortfall, as a decimal string.
     */
    public static function negativeStockNotAllowed(string $quantity): self
    {
        return new self(sprintf(
            'This company does not allow stock to go negative, and the movement takes the quantity on hand below zero by %s.',
            $quantity
        ));
    }

    public static function movementNotPosted(StockMovementStatus $status): self
    {
        return new self(sprintf(
            'Only a posted stock movement can be reversed; this one is %s.',
            $status->label()
        ));
    }

    public static function alreadyReversed(StockMovementType $type): self
    {
        return new self(sprintf(
            'This %s has already been reversed, so it cannot be reversed a second time.',
            $type->label()
        ));
    }

    public static function movementIsItselfAReversal(): self
    {
        return new self('A reversal of a movement cannot itself be reversed; reverse the movement it corrects instead.');
    }

    /**
     * A transfer is one event written as two movements, so reversing one half
     * would leave the same stock counted in both warehouses at once.
     */
    public static function transferCannotBeReversed(): self
    {
        return new self('A transfer is corrected by transferring the stock back, not by reversing one of its halves.');
    }

    /**
     * A product posts its stock to accounts it names itself. Without them a
     * movement has nowhere to be recorded, which is why the goods the
     * catalogue holds are required to name both.
     */
    public static function productHasNoInventoryAccount(Product $product): self
    {
        return new self(sprintf(
            'Product %s names no inventory account, so its stock has nowhere to be carried.',
            $product->sku
        ));
    }

    public static function productHasNoCostAccount(Product $product): self
    {
        return new self(sprintf(
            'Product %s names no cost of sales account, so the cost of what is issued from it has nowhere to go.',
            $product->sku
        ));
    }

    /**
     * An item that has moved is stuck with the kind of thing it is.
     *
     * Turning goods into a service, or the other way round, would leave the
     * layers it already has belonging to an item that no longer holds stock,
     * and the cost of what moved through it unreachable.
     */
    public static function productTypeLocked(Product $product): self
    {
        return new self(sprintf(
            'Product %s has stock movements, so it cannot stop being %s.',
            $product->sku,
            $product->product_type->label()
        ));
    }

    /**
     * The costing method may only be chosen while the shelves are empty.
     *
     * Once a movement exists the layers it created were priced by whichever
     * method was in force, so switching now would value what is left by a rule
     * the stock was never bought under.
     */
    public static function costingMethodLocked(string $current, string $requested): self
    {
        return new self(sprintf(
            'The costing method is %s and cannot be changed to %s while any stock movement exists.',
            $current,
            $requested
        ));
    }
}
