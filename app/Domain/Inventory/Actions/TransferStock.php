<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\DTOs\StockTransferData;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\StockMovement;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Stock moving between two of the company's own warehouses.
 *
 * The quantity on hand is kept per warehouse, so one movement cannot describe
 * a transfer: a stock row could not say that the goods left one place and
 * arrived at another. Two movements are written instead, both inside one
 * transaction so a half-finished transfer is never visible.
 *
 * The cost travels with the stock rather than being worked out again at the
 * far end. The layers that funded the move are read once, and the destination
 * opens a layer of its own at the price those layers charged; what the goods
 * are worth does not change by crossing a yard, so neither movement posts a
 * journal. The inventory account is one account for the whole company, and a
 * transfer leaves the total in it alone.
 *
 * A transfer out of a warehouse that has not got the stock is refused by the
 * same policy that governs an issue, because that is what it is: stock leaving
 * a shelf. The stock is not on the shelf, whichever way it goes afterwards.
 */
final class TransferStock
{
    public function __construct(
        private readonly StockPolicy $policy,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
    ) {}

    /**
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function __invoke(StockTransferData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $company = $this->recorder->company($data->companyId);

            $this->policy->assertCostingImplemented($company);

            $quantity = Quantity::of($data->quantity);

            if (! Quantity::isPositive($quantity)) {
                throw StockStateException::nonPositiveQuantity('transfer');
            }

            if ($data->fromWarehouseId === $data->toWarehouseId) {
                throw StockStateException::sameWarehouse($data->fromWarehouseId);
            }

            $product = $this->recorder->product($data->companyId, $data->productId);
            $from = $this->recorder->warehouse($data->companyId, $data->fromWarehouseId);
            $to = $this->recorder->warehouse($data->companyId, $data->toWarehouseId);

            $productId = (int) $product->getKey();

            $plan = $this->layers->plan(
                companyId: $data->companyId,
                productId: $productId,
                warehouseId: (int) $from->getKey(),
                quantity: $quantity,
            );

            if ($plan->isShort() && ! $this->policy->allowsNegativeStock($company)) {
                throw StockStateException::negativeStockNotAllowed($plan->shortfall);
            }

            $out = $this->recorder->record(new StockMovementDraft(
                companyId: $data->companyId,
                product: $product,
                warehouse: $from,
                type: StockMovementType::TransferOut,
                movementDate: $data->movementDate,
                quantity: Quantity::negate($quantity),
                totalCost: $plan->totalCost(),
                reference: $data->reference,
                memo: $data->memo,
                userId: $data->userId,
            ));

            $this->layers->apply($data->companyId, $out, $plan, $data->movementDate);

            $in = $this->recorder->record(new StockMovementDraft(
                companyId: $data->companyId,
                product: $product,
                warehouse: $to,
                type: StockMovementType::TransferIn,
                movementDate: $data->movementDate,
                quantity: $quantity,
                totalCost: $plan->totalCost(),
                reference: $data->reference,
                memo: $data->memo,
                userId: $data->userId,
            ));

            // The destination's own layer, priced at what the stock cost when
            // it left. It is a layer like any other: the stock that arrived
            // here will be issued from here, and it cost what it cost when it
            // was bought rather than what it would cost to buy now.
            $this->layers->open(
                companyId: $data->companyId,
                productId: $productId,
                warehouseId: (int) $to->getKey(),
                receiptMovementId: (int) $in->getKey(),
                receivedOn: $data->movementDate,
                quantity: $quantity,
                unitCost: $plan->unitCost()->toDecimal(),
            );

            return ['out' => $out, 'in' => $in];
        });
    }
}
