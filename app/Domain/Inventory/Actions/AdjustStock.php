<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * A stock count that disagrees with the books.
 *
 * An adjustment is the one movement whose direction comes from its own sign
 * rather than from its type, because a count can go either way. The two
 * directions are not mirror images of each other, though, and the difference
 * is the whole of this class:
 *
 * A shortfall is stock leaving, so it is priced the way any issue is — from
 * the layers, oldest first — and what the count could not find is written off
 * at exactly what it had cost. It posts Dr the counter account, Cr inventory.
 *
 * A surplus is stock arriving from nowhere, so there is no layer to price it
 * from and the caller has to say what those units are worth. They open a new
 * layer at that price, and it posts Dr inventory, Cr the counter account.
 *
 * The counter account is the caller's in both directions. A write-off belongs
 * on a shrinkage or loss account and a surplus on a gain account, and which
 * accounts those are is a decision about the company's chart rather than a
 * fact about the stock.
 */
final class AdjustStock
{
    public function __construct(
        private readonly StockPolicy $policy,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
    ) {}

    public function __invoke(StockAdjustmentData $data): StockMovement
    {
        return DB::transaction(function () use ($data): StockMovement {
            $company = $this->recorder->company($data->companyId);

            $this->policy->assertCostingImplemented($company);

            $quantity = Quantity::of($data->quantity);

            if (Quantity::isZero($quantity)) {
                throw StockStateException::adjustmentToZero();
            }

            $product = $this->recorder->product($data->companyId, $data->productId);
            $warehouse = $this->recorder->warehouse($data->companyId, $data->warehouseId);
            $accounts = $this->recorder->accounts($product);

            return Quantity::isPositive($quantity)
                ? $this->recordSurplus($data, $quantity, $accounts)
                : $this->recordShortfall($data, $quantity, $accounts);
        });
    }

    /**
     * @param  array{inventory: int, cost: int}  $accounts
     */
    private function recordSurplus(StockAdjustmentData $data, string $quantity, array $accounts): StockMovement
    {
        if ($data->unitCost === null) {
            throw StockStateException::adjustmentCostRequired();
        }

        $unitCost = Money::of($data->unitCost);

        if ($unitCost->isNegative()) {
            throw StockStateException::nonPositiveCost();
        }

        $product = $this->recorder->product($data->companyId, $data->productId);
        $warehouse = $this->recorder->warehouse($data->companyId, $data->warehouseId);

        $movement = $this->recorder->record(new StockMovementDraft(
            companyId: $data->companyId,
            product: $product,
            warehouse: $warehouse,
            type: StockMovementType::Adjustment,
            movementDate: $data->movementDate,
            quantity: $quantity,
            totalCost: $unitCost->times($quantity),
            debitAccountId: $accounts['inventory'],
            creditAccountId: $data->counterAccountId,
            reference: $data->reason,
            memo: $data->reason,
            userId: $data->userId,
        ));

        $this->layers->open(
            companyId: $data->companyId,
            productId: (int) $product->getKey(),
            warehouseId: (int) $warehouse->getKey(),
            receiptMovementId: (int) $movement->getKey(),
            receivedOn: $data->movementDate,
            quantity: $quantity,
            unitCost: $unitCost->toDecimal(),
        );

        return $movement;
    }

    /**
     * @param  array{inventory: int, cost: int}  $accounts
     */
    private function recordShortfall(StockAdjustmentData $data, string $quantity, array $accounts): StockMovement
    {
        $company = $this->recorder->company($data->companyId);
        $product = $this->recorder->product($data->companyId, $data->productId);
        $warehouse = $this->recorder->warehouse($data->companyId, $data->warehouseId);

        $plan = $this->layers->plan(
            companyId: $data->companyId,
            productId: (int) $product->getKey(),
            warehouseId: (int) $warehouse->getKey(),
            quantity: Quantity::absolute($quantity),
        );

        if ($plan->isShort() && ! $this->policy->allowsNegativeStock($company)) {
            throw StockStateException::negativeStockNotAllowed($plan->shortfall);
        }

        $movement = $this->recorder->record(new StockMovementDraft(
            companyId: $data->companyId,
            product: $product,
            warehouse: $warehouse,
            type: StockMovementType::Adjustment,
            movementDate: $data->movementDate,
            quantity: $quantity,
            totalCost: $plan->totalCost(),
            debitAccountId: $data->counterAccountId,
            creditAccountId: $accounts['inventory'],
            reference: $data->reason,
            memo: $data->reason,
            userId: $data->userId,
        ));

        $this->layers->apply($data->companyId, $movement, $plan, $data->movementDate);

        return $movement;
    }
}
