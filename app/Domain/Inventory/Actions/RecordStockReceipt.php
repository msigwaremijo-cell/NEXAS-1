<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\DTOs\StockReceiptData;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\Company;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Stock arriving in a warehouse, and what it cost.
 *
 * A receipt is the only movement that decides a price rather than reading one.
 * Everything that leaves later is costed from the layer this writes, so the
 * unit cost here is the figure every future issue out of this delivery will be
 * priced at — and, being frozen onto the layer, it cannot be restated after
 * the fact without reversing this movement.
 *
 * It posts one balanced entry:
 *
 *   Dr  the product's inventory account   what the delivery was worth
 *   Cr  the account the delivery was met by
 *
 * Which account that is depends on whether the goods were bought. A **purchase
 * receipt** names a supplier, so it credits the company's goods received not
 * invoiced account: the company has the goods and owes for them, and the bill
 * that follows clears that liability rather than receiving the stock a second
 * time. The caller's own counter account is ignored in that case, because the
 * account is what makes the GRNI clearing work and naming it is not the
 * caller's to do.
 *
 * A **non-purchase receipt** names no supplier and must name the account it
 * was met by, since that depends on why the goods came rather than on what
 * they are: a return is met by cost of sales, an owner's contribution by
 * capital. Such a receipt is never owed for, so no bill can clear it.
 *
 * Stock that arrived at no cost opens a layer at nothing and posts nothing.
 * There is no value to add to the inventory account, and a journal of two
 * zeroes would be a posting that says no more than the movement already does.
 */
final class RecordStockReceipt
{
    public function __construct(
        private readonly StockPolicy $policy,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
    ) {}

    public function __invoke(StockReceiptData $data): StockMovement
    {
        return DB::transaction(function () use ($data): StockMovement {
            $company = $this->recorder->company($data->companyId);

            $this->policy->assertCostingImplemented($company);

            $quantity = Quantity::of($data->quantity);

            if (! Quantity::isPositive($quantity)) {
                throw StockStateException::nonPositiveQuantity('receipt');
            }

            $unitCost = Money::of($data->unitCost);

            if ($unitCost->isNegative()) {
                throw StockStateException::nonPositiveCost();
            }

            $product = $this->recorder->product($data->companyId, $data->productId);
            $warehouse = $this->recorder->warehouse($data->companyId, $data->warehouseId);
            $accounts = $this->recorder->accounts($product);

            $movement = $this->recorder->record(new StockMovementDraft(
                companyId: $data->companyId,
                product: $product,
                warehouse: $warehouse,
                type: StockMovementType::Receipt,
                movementDate: $data->movementDate,
                quantity: $quantity,
                totalCost: $unitCost->times($quantity),
                debitAccountId: $accounts['inventory'],
                creditAccountId: $this->counterAccount($data, $company),
                supplierId: $data->supplierId,
                reference: $data->reference,
                memo: $data->memo,
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
        });
    }

    /**
     * The account a delivery is credited to.
     *
     * A purchase is met by goods received not invoiced whatever the caller
     * said, because a receipt credited anywhere else would leave the bill that
     * follows with nothing to clear. A delivery that names no supplier is the
     * caller's to meet, and has to be named.
     */
    private function counterAccount(StockReceiptData $data, Company $company): int
    {
        if ($data->supplierId !== null) {
            $this->recorder->supplier($data->companyId, $data->supplierId);

            return $this->policy->purchaseAccounts($company)['grni'];
        }

        if ($data->counterAccountId === null) {
            throw StockStateException::receiptNeedsCounterAccount();
        }

        return $data->counterAccountId;
    }
}
