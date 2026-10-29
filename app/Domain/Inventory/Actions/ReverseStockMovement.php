<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Inventory\DTOs\LayerDrawPlan;
use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Services\FifoLayerLedger;
use App\Domain\Inventory\Services\StockMovementRecorder;
use App\Domain\Inventory\Services\StockPolicy;
use App\Models\StockLayer;
use App\Models\StockMovement;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Correcting a stock movement the only way stock may be corrected: by writing
 * an opposite movement.
 *
 * A posted movement is never edited, so this writes a second one whose quantity
 * carries the other sign and whose `reverses_movement_id` names the first, and
 * the unique index on that column is what makes "at most once" true even if the
 * same reversal is asked for twice at once.
 *
 * Which way the reversal goes is decided by the original's own sign rather than
 * by a rule per type. A movement that put stock in is answered by one that
 * takes it out, and one that took stock out is answered by one that puts it
 * back, which covers the adjustment — the type that can go either way — without
 * a special case.
 *
 * The stock side and the ledger side are corrected in the two different ways
 * each of them allows:
 *
 * Stock that goes back in opens a new layer at the price the stock left at,
 * taken from the movement being reversed. It does not go back into the layers
 * that funded the original issue, because `stock_layer_consumptions` is
 * append-only and a layer's remaining quantity is only ever written downwards;
 * the value is the same and the layer that holds it is a new one.
 *
 * Stock that comes back out is drawn from the layers the original opened, which
 * is what `preferLayerIds` is for: reversing a receipt has to take back the
 * stock that receipt brought in, not whatever happens to be oldest.
 *
 * The entry the movement made is reversed as a whole, through the same
 * ReverseJournal the rest of the books use, so the original is marked reversed
 * and the two entries net to nothing account by account.
 */
final class ReverseStockMovement
{
    public function __construct(
        private readonly StockPolicy $policy,
        private readonly StockMovementRecorder $recorder,
        private readonly FifoLayerLedger $layers,
        private readonly ReverseJournal $reverseJournal,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        int $companyId,
        int $movementId,
        ?string $movementDate = null,
        ?string $memo = null,
        ?int $userId = null,
    ): StockMovement {
        return DB::transaction(function () use (
            $companyId,
            $movementId,
            $movementDate,
            $memo,
            $userId
        ): StockMovement {
            $company = $this->recorder->company($companyId);

            $this->policy->assertCostingImplemented($company);

            // Locked for the length of the transaction, so two reversals asked
            // for at the same moment find the movement in the same state and
            // the unique index is what settles which of them wins.
            $original = StockMovement::query()
                ->where('company_id', $companyId)
                ->whereKey($movementId)
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                throw StockStateException::unknownMovement($movementId);
            }

            $this->assertCanBeReversed($original);

            $date = $movementDate ?? $original->movement_date->toDateString();

            $product = $this->recorder->product($companyId, (int) $original->product_id);
            $warehouse = $this->recorder->warehouse($companyId, (int) $original->warehouse_id);
            $productId = (int) $product->getKey();
            $warehouseId = (int) $warehouse->getKey();

            // A movement that added stock is undone by taking stock out, and a
            // movement that took stock out is undone by putting it back.
            $takesStockOut = Quantity::isPositive((string) $original->quantity);
            $quantity = Quantity::absolute((string) $original->quantity);

            $journal = $original->journal_id === null
                ? null
                : ($this->reverseJournal)($original->journal, $date, $memo, $userId);

            [$totalCost, $plan] = $takesStockOut
                ? $this->costOfTakingItBackOut($companyId, $productId, $warehouseId, $quantity, $original)
                : [Money::of((string) $original->total_cost), null];

            $reversal = $this->recorder->record(new StockMovementDraft(
                companyId: $companyId,
                product: $product,
                warehouse: $warehouse,
                type: $original->movement_type->reversed(),
                movementDate: $date,
                quantity: Quantity::negate((string) $original->quantity),
                totalCost: $totalCost,
                memo: $memo,
                userId: $userId,
                reverses: $original,
                journal: $journal,
            ));

            if ($plan !== null) {
                $this->layers->apply($companyId, $reversal, $plan, $date);
            } else {
                // Back onto the shelf at what it cost when it left, so the
                // value that came out of the books goes back in unchanged.
                $this->layers->open(
                    companyId: $companyId,
                    productId: $productId,
                    warehouseId: $warehouseId,
                    receiptMovementId: (int) $reversal->getKey(),
                    receivedOn: $date,
                    quantity: $quantity,
                    unitCost: Money::of((string) $original->unit_cost)->toDecimal(),
                );
            }

            $original->forceFill(['status' => StockMovementStatus::Reversed])->save();

            $this->audit->record('stock.movement.reversed', $original, [
                'status' => StockMovementStatus::Posted->value,
            ], [
                'status' => StockMovementStatus::Reversed->value,
                'reversal_movement_id' => (int) $reversal->getKey(),
                'journal_id' => $journal?->getKey(),
            ]);

            return $reversal;
        });
    }

    /**
     * The stock coming back out, priced from the layers the movement opened.
     *
     * The plan is preferred to those layers rather than restricted to them, so
     * a receipt whose own stock has been issued and partly replaced is answered
     * over the stock that is really there. A company that does not allow stock
     * to go negative is refused when even that is not enough to fund it.
     *
     * @return array{0: Money, 1: LayerDrawPlan}
     */
    private function costOfTakingItBackOut(
        int $companyId,
        int $productId,
        int $warehouseId,
        string $quantity,
        StockMovement $original,
    ): array {
        $plan = $this->layers->plan(
            companyId: $companyId,
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $quantity,
            preferLayerIds: $this->layersOpenedBy($companyId, (int) $original->getKey()),
        );

        if ($plan->isShort() && ! $this->policy->allowsNegativeStock($this->recorder->company($companyId))) {
            throw StockStateException::negativeStockNotAllowed($plan->shortfall);
        }

        return [$plan->totalCost(), $plan];
    }

    /**
     * @return array<int, int>
     */
    private function layersOpenedBy(int $companyId, int $movementId): array
    {
        return StockLayer::query()
            ->where('company_id', $companyId)
            ->where('receipt_movement_id', $movementId)
            ->pluck('id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    private function assertCanBeReversed(StockMovement $original): void
    {
        if (! $original->isPosted()) {
            throw StockStateException::movementNotPosted($original->status);
        }

        if ($original->isReversal()) {
            throw StockStateException::movementIsItselfAReversal();
        }

        // Half of a transfer cannot be undone on its own: the same stock would
        // then be counted in both warehouses at once.
        if ($original->movement_type->isTransfer()) {
            throw StockStateException::transferCannotBeReversed();
        }

        if ($original->reversal()->exists()) {
            throw StockStateException::alreadyReversed($original->movement_type);
        }
    }
}
