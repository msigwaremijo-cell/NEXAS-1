<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Inventory\DTOs\StockMovementDraft;
use App\Domain\Inventory\Enums\StockMovementStatus;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Models\Company;
use App\Models\Journal;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;

/**
 * Writes one stock movement and the ledger entry it makes.
 *
 * A stock movement is two things at once: a change in what is on the shelf, and
 * a change in what that stock is worth. This class does the second half — it
 * builds the two-line journal from the accounts the movement names, posts it
 * through the same PostJournal the rest of the books use, and then writes the
 * movement row carrying the entry's id.
 *
 * The order matters. `journal_id` is frozen by a trigger the moment the
 * movement exists, so the entry has to be posted before the movement can name
 * it — there is no second visit to fill it in.
 *
 * A movement worth nothing posts nothing. Stock that arrived free has no value
 * to add to the inventory account, and units issued against a company that
 * allows negative stock have no layer to be priced from; in both cases a
 * journal of two zeroes would be a posting that says nothing, and the books
 * are left alone.
 *
 * The resolution of the company, the product and the warehouse lives here too,
 * because a movement cannot be written without them: every one of them is
 * looked up inside the tenant, so an id belonging to another company finds
 * nothing rather than reaching across the books.
 */
final class StockMovementRecorder
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly AuditTrail $audit,
    ) {}

    public function company(int $companyId): Company
    {
        return Company::query()->findOrFail($companyId);
    }

    public function product(int $companyId, int $productId): Product
    {
        $product = Product::query()
            ->where('company_id', $companyId)
            ->whereKey($productId)
            ->first();

        if ($product === null) {
            throw StockStateException::unknownProduct($productId);
        }

        if (! $product->holdsStock()) {
            throw StockStateException::productDoesNotHoldStock($product);
        }

        return $product;
    }

    public function warehouse(int $companyId, int $warehouseId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('company_id', $companyId)
            ->whereKey($warehouseId)
            ->first();

        if ($warehouse === null) {
            throw StockStateException::unknownWarehouse($warehouseId);
        }

        if (! $warehouse->status->acceptsNewMovements()) {
            throw StockStateException::warehouseNotOpen($warehouse);
        }

        return $warehouse;
    }

    /**
     * The supplier a purchase receipt is attributed to, looked up inside the
     * tenant so an id from another company's books finds nothing.
     */
    public function supplier(int $companyId, int $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->where('company_id', $companyId)
            ->whereKey($supplierId)
            ->first();

        if ($supplier === null) {
            throw StockStateException::unknownSupplier($supplierId);
        }

        return $supplier;
    }

    /**
     * The two ledger accounts an item's stock posts through.
     *
     * Both are named by the product rather than by the caller, so the same item
     * always reaches the same pair of accounts. An item carried as stock names
     * both, which is what the catalogue requires when such an item is created.
     *
     * @return array{inventory: int, cost: int}
     */
    public function accounts(Product $product): array
    {
        if ($product->inventory_account_id === null) {
            throw StockStateException::productHasNoInventoryAccount($product);
        }

        if ($product->expense_account_id === null) {
            throw StockStateException::productHasNoCostAccount($product);
        }

        return [
            'inventory' => (int) $product->inventory_account_id,
            'cost' => (int) $product->expense_account_id,
        ];
    }

    public function record(StockMovementDraft $draft): StockMovement
    {
        // A movement usually makes its own entry out of the accounts it names.
        // A reversal does not: the entry it answers is already posted, and what
        // the ledger needs is a mirror image of that entry rather than a fresh
        // two-line entry built from this movement's figures, so the draft
        // arrives holding the reversal that was posted for it.
        $journal = $draft->journal ?? $this->postEntry($draft);

        $movement = StockMovement::create([
            'company_id' => $draft->companyId,
            'product_id' => (int) $draft->product->getKey(),
            'warehouse_id' => (int) $draft->warehouse->getKey(),
            // Empty on everything but a purchase receipt, because a bill may
            // only clear the deliveries of its own supplier.
            'supplier_id' => $draft->supplierId,
            'movement_type' => $draft->type->value,
            'movement_date' => $draft->movementDate,
            // A movement is written already posted. There is no draft state to
            // move through: the layers it draws on are locked by the
            // transaction that is writing it, so the moment it exists it has
            // happened, and the only thing left to do to it is reverse it.
            'status' => StockMovementStatus::Posted->value,
            'quantity' => $draft->quantity,
            'unit_cost' => $draft->unitCost()->toDecimal(),
            'total_cost' => $draft->totalCost->toDecimal(),
            'journal_id' => $journal?->getKey(),
            'reverses_movement_id' => $draft->reverses?->getKey(),
            // What the movement came from, where it came from a document. Set
            // at creation because a trigger freezes both columns afterwards,
            // and it is what a credit note follows back to the invoice line
            // whose issue it has to answer.
            'source_type' => $draft->sourceType,
            'source_id' => $draft->sourceId,
            'created_by_user_id' => $draft->userId,
        ]);

        $this->audit->record('stock.movement.posted', $movement, null, [
            'movement_type' => $draft->type->value,
            'product_sku' => $draft->product->sku,
            'warehouse_code' => $draft->warehouse->code,
            'quantity' => $draft->quantity,
            'total_cost' => $draft->totalCost->toDecimal(),
            'journal_no' => $journal?->journal_no,
        ]);

        return $movement;
    }

    private function postEntry(StockMovementDraft $draft): ?Journal
    {
        if (! $draft->carriesValue()) {
            return null;
        }

        $description = $draft->description();

        $journal = ($this->create)(new JournalEntryData(
            companyId: $draft->companyId,
            postingDate: $draft->movementDate,
            // One journal type for the whole module. What a movement's entry is
            // worth reading as is which accounts it names and what the memo
            // says, not a type per direction: a receipt and an issue differ by
            // which side of the inventory account they sit on, and that is
            // already said by the lines.
            journalType: JournalType::Stock,
            lines: [
                JournalLineData::debit($draft->debitAccountId, $draft->totalCost, $description),
                JournalLineData::credit($draft->creditAccountId, $draft->totalCost, $description),
            ],
            reference: $draft->reference,
            memo: $draft->memo,
            documentDate: $draft->movementDate,
            createdByUserId: $draft->userId,
        ));

        return ($this->post)($journal, null, $draft->userId);
    }
}
