<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Models\Product;
use App\Models\Supplier;
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

    /**
     * A purchase has no goods received not invoiced account to clear against.
     */
    public static function grniAccountNotConfigured(): self
    {
        return new self(
            'This company names no goods received not invoiced account, so a purchase receipt has nowhere to credit and a bill has nothing to clear. Set one in the inventory settings.'
        );
    }

    /**
     * A bill disagrees with the receipt it settles and there is nowhere for the
     * difference to go.
     */
    public static function purchaseVarianceAccountNotConfigured(): self
    {
        return new self(
            'This company names no purchase variance account, so a difference between what a receipt was booked at and what the bill says has nowhere to post. Set one in the inventory settings.'
        );
    }

    /**
     * A receipt that is not a purchase has to say what it was met by.
     *
     * Without a supplier there is nothing for a bill to clear, so the movement
     * is a non-purchase receipt — opening stock, a customer return, an owner's
     * contribution — and the account it was met by is the caller's to name.
     */
    public static function receiptNeedsCounterAccount(): self
    {
        return new self(
            'A stock receipt must name either the supplier it came from or the counter account it was met by.'
        );
    }

    public static function unknownSupplier(int $supplierId): self
    {
        return new self(sprintf(
            'No supplier with id %d belongs to this company, so the receipt cannot be attributed to it.',
            $supplierId
        ));
    }

    /**
     * A goods line was billed before the goods arrived, or for more than did.
     *
     * Nothing here receives the stock. The receipt moves it and opens the layer
     * that prices every later issue; the bill only settles what is owed for
     * what has already been received and not yet billed.
     */
    public static function noOpenReceipt(Product $product, Supplier $supplier): self
    {
        return new self(sprintf(
            'Supplier %s has no received and unbilled %s for this bill line to clear; receive the goods before billing for them.',
            $supplier->name,
            $product->sku
        ));
    }

    /**
     * More was billed than has been received and not yet billed.
     *
     * @param  string  $wanted  The billed quantity, as a decimal string.
     * @param  string  $available  What is still open on the supplier's receipts.
     */
    public static function insufficientOpenReceipt(
        Product $product,
        Supplier $supplier,
        string $wanted,
        string $available,
    ): self {
        return new self(sprintf(
            'Supplier %s has only %s of %s received and unbilled, and this bill line asks to clear %s.',
            $supplier->name,
            $available,
            $product->sku,
            $wanted
        ));
    }

    /**
     * A receipt a bill has already drawn on cannot be reversed.
     *
     * The bill cleared its GRNI against this receipt, so undoing the receipt
     * would leave the clearing pointing at stock that had never arrived. The
     * bill is corrected first, by reversing it, and only then the receipt.
     */
    public static function receiptAlreadyCleared(): self
    {
        return new self(
            'A bill has already cleared part of this receipt, so the receipt cannot be reversed until that bill is reversed first.'
        );
    }

    /**
     * A sale of goods has nowhere to issue them from.
     *
     * Issuing needs a warehouse to draw the layers out of, and the company has
     * not said which one a sale uses by default.
     */
    public static function noDefaultWarehouse(): self
    {
        return new self(
            'This company names no default warehouse, so a sale of goods has nowhere to issue the stock from. Set one on the warehouses screen.'
        );
    }

    /**
     * A credit note line puts a stock item back, but the invoice line it
     * corrects was never issued from a warehouse.
     *
     * This happens when the invoice was raised and issued before the item was
     * made a stock item, or when the issue was itself reversed. Either way
     * there is no movement to answer, and the whole credit note is refused so
     * the ledger and the shelf stay in step.
     */
    public static function noIssueToReturn(string $description): self
    {
        return new self(sprintf(
            'Credit note line [%s] gives back stock, but the invoice line it corrects was never issued from a warehouse, so there is no movement to return.',
            $description
        ));
    }
}
