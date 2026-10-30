<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockIssueData;
use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\StockMovement;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Stock leaving a warehouse, at what it cost to buy.
 *
 * Nothing here decides a price. The stock that leaves is priced by the layers
 * it came out of, oldest first, so what an issue is worth is what the company
 * actually paid for those particular units rather than an average of
 * everything on the shelf.
 *
 * It posts one balanced entry:
 *
 *   Dr  the product's cost of sales account   what the stock had cost
 *   Cr  the product's inventory account
 *
 * Both accounts are named by the product, which is why the caller names
 * neither: the same item always reaches the same expense line.
 *
 * The layers are read with a locking read inside this transaction, so two
 * issues asked for at the same moment are answered one after the other and
 * cannot be given the same units twice. When the request is more than the
 * shelves hold, the company's policy decides: by default the issue is refused
 * outright, and a company that has allowed negative stock carries the unfunded
 * units at no cost rather than refusing the movement.
 */
final class IssueStock
{
    public function __construct(
        private readonly StockPolicy $policy,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
    ) {}

    public function __invoke(StockIssueData $data): StockMovement
    {
        return DB::transaction(function () use ($data): StockMovement {
            $company = $this->recorder->company($data->companyId);

            $this->policy->assertCostingImplemented($company);

            $quantity = Quantity::of($data->quantity);

            if (! Quantity::isPositive($quantity)) {
                throw StockStateException::nonPositiveQuantity('issue');
            }

            $product = $this->recorder->product($data->companyId, $data->productId);
            $warehouse = $this->recorder->warehouse($data->companyId, $data->warehouseId);
            $accounts = $this->recorder->accounts($product);

            $plan = $this->layers->plan(
                companyId: $data->companyId,
                productId: (int) $product->getKey(),
                warehouseId: (int) $warehouse->getKey(),
                quantity: $quantity,
            );

            if ($plan->isShort() && ! $this->policy->allowsNegativeStock($company)) {
                throw StockStateException::negativeStockNotAllowed($plan->shortfall);
            }

            $movement = $this->recorder->record(new StockMovementDraft(
                companyId: $data->companyId,
                product: $product,
                warehouse: $warehouse,
                type: StockMovementType::Issue,
                movementDate: $data->movementDate,
                // The sign is the direction: stock going out is written
                // negative, which is what makes the quantity on hand a single
                // SUM over this table and nothing else.
                quantity: Quantity::negate($quantity),
                totalCost: $plan->totalCost(),
                debitAccountId: $accounts['cost'],
                creditAccountId: $accounts['inventory'],
                reference: $data->reference,
                memo: $data->memo,
                userId: $data->userId,
                sourceType: $data->sourceType,
                sourceId: $data->sourceId,
            ));

            $this->layers->apply($data->companyId, $movement, $plan, $data->movementDate);

            return $movement;
        });
    }
}
