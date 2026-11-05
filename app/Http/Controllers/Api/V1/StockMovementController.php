<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\IssueStock;
use App\Domain\Inventory\Actions\RecordStockReceipt;
use App\Domain\Inventory\Actions\ReverseStockMovement;
use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockIssueData;
use App\Domain\Inventory\DTOs\StockReceiptData;
use App\Domain\Inventory\DTOs\StockTransferData;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The stock movements, and the reading of them.
 *
 * Every rule about what a movement does to the stock and to the books lives in
 * the action the route names. This class validates the shape of the request,
 * authorises it, and serialises the answer: which layers an issue draws on, what
 * it is worth, and which accounts the entry lands on are decisions of the
 * domain, not of a controller.
 *
 * The counter account is named by the caller only where it is genuinely the
 * caller's to name. An issue's cost goes to the product's own expense account,
 * so no account is accepted for it; a receipt or an adjustment is met by
 * whatever the stock came from or went to — a supplier, a cost of sales
 * account, a shrinkage account — and only the caller knows which.
 *
 * Reversing is its own route rather than an edit of a movement, because a
 * posted movement is not edited. It names the movement by its public id, as the
 * rest of this surface names things by ULID, and the action resolves it again
 * inside the tenant before anything is written.
 */
class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StockMovement::class);

        $filters = $request->validate([
            'product_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'warehouse_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'movement_type' => ['sometimes', 'nullable', Rule::enum(StockMovementType::class)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = StockMovement::query()
            ->where('company_id', $this->companyId($request))
            ->with(['product', 'warehouse'])
            ->when(filled($filters['product_id'] ?? null), fn ($query) => $query->where('product_id', $filters['product_id']))
            ->when(filled($filters['warehouse_id'] ?? null), fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
            ->when(filled($filters['movement_type'] ?? null), fn ($query) => $query->where('movement_type', $filters['movement_type']))
            ->when(filled($filters['from'] ?? null), fn ($query) => $query->where('movement_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn ($query) => $query->where('movement_date', '<=', $filters['to']))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (StockMovement $movement): array => $this->present($movement),
                $movements->items()
            ),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'last_page' => $movements->lastPage(),
            ],
        ]);
    }

    public function storeReceipt(Request $request, RecordStockReceipt $receipt): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'product_id' => $this->productRule($companyId),
            'warehouse_id' => $this->warehouseRule($companyId),
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => $this->positiveQuantityRule(),
            // A decimal string like every other money field on this surface: a
            // JSON number cannot round-trip DECIMAL(19,4).
            'unit_cost' => ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
            // Named only for a delivery that names no supplier. A purchase
            // receipt credits the GRNI account whatever the caller says, so
            // requiring an account here would be asking for one that is
            // ignored.
            'counter_account_id' => ['sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            // Naming a supplier is what makes this a purchase: it credits GRNI
            // and opens it to be cleared by the bill that follows.
            'supplier_id' => ['sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $movement = $receipt(new StockReceiptData(
            companyId: $companyId,
            productId: (int) $data['product_id'],
            warehouseId: (int) $data['warehouse_id'],
            movementDate: $data['movement_date'],
            quantity: $data['quantity'],
            unitCost: $data['unit_cost'],
            counterAccountId: isset($data['counter_account_id']) ? (int) $data['counter_account_id'] : null,
            supplierId: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            reference: $data['reference'] ?? null,
            memo: $data['memo'] ?? null,
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => $this->present($movement)], 201);
    }

    public function storeIssue(Request $request, IssueStock $issue): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'product_id' => $this->productRule($companyId),
            'warehouse_id' => $this->warehouseRule($companyId),
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => $this->positiveQuantityRule(),
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $movement = $issue(new StockIssueData(
            companyId: $companyId,
            productId: (int) $data['product_id'],
            warehouseId: (int) $data['warehouse_id'],
            movementDate: $data['movement_date'],
            quantity: $data['quantity'],
            reference: $data['reference'] ?? null,
            memo: $data['memo'] ?? null,
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => $this->present($movement)], 201);
    }

    public function storeAdjustment(Request $request, AdjustStock $adjust): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'product_id' => $this->productRule($companyId),
            'warehouse_id' => $this->warehouseRule($companyId),
            'movement_date' => ['required', 'date_format:Y-m-d'],
            // Signed, because a count can be over or short. The action refuses
            // zero: an adjustment that changes nothing is not an adjustment.
            'quantity' => ['required', 'string', 'max:32', 'regex:/^-?\d+(\.\d+)?$/'],
            // Only a count that found more than the books say has to name a
            // price: there is no layer to price those units from.
            'unit_cost' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
            'counter_account_id' => $this->accountRule($companyId),
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $movement = $adjust(new StockAdjustmentData(
            companyId: $companyId,
            productId: (int) $data['product_id'],
            warehouseId: (int) $data['warehouse_id'],
            movementDate: $data['movement_date'],
            quantity: $data['quantity'],
            counterAccountId: (int) $data['counter_account_id'],
            unitCost: $data['unit_cost'] ?? null,
            reason: $data['reason'] ?? null,
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => $this->present($movement)], 201);
    }

    public function storeTransfer(Request $request, TransferStock $transfer): JsonResponse
    {
        $this->authorize('create', StockMovement::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'product_id' => $this->productRule($companyId),
            'from_warehouse_id' => $this->warehouseRule($companyId),
            'to_warehouse_id' => $this->warehouseRule($companyId),
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'quantity' => $this->positiveQuantityRule(),
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $movements = $transfer(new StockTransferData(
            companyId: $companyId,
            productId: (int) $data['product_id'],
            fromWarehouseId: (int) $data['from_warehouse_id'],
            toWarehouseId: (int) $data['to_warehouse_id'],
            movementDate: $data['movement_date'],
            quantity: $data['quantity'],
            reference: $data['reference'] ?? null,
            memo: $data['memo'] ?? null,
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => [
            'out' => $this->present($movements['out']),
            'in' => $this->present($movements['in']),
        ]], 201);
    }

    /**
     * The correction of a movement, written as a movement of its own.
     */
    public function reverse(Request $request, string $movement, ReverseStockMovement $reverse): JsonResponse
    {
        $record = StockMovement::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $movement)
            ->firstOrFail();

        $this->authorize('reverse', $record);

        $data = $request->validate([
            'movement_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $reversal = $reverse(
            companyId: (int) $record->company_id,
            movementId: (int) $record->getKey(),
            movementDate: $data['movement_date'] ?? null,
            memo: $data['memo'] ?? null,
            userId: $request->user()?->getKey(),
        );

        return response()->json(['data' => $this->present($reversal)], 201);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function productRule(int $companyId): array
    {
        return [
            'required', 'integer', 'min:1',
            Rule::exists('products', 'id')->where('company_id', $companyId),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function warehouseRule(int $companyId): array
    {
        return [
            'required', 'integer', 'min:1',
            Rule::exists('warehouses', 'id')->where('company_id', $companyId),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function accountRule(int $companyId): array
    {
        return [
            'required', 'integer', 'min:1',
            Rule::exists('accounts', 'id')->where('company_id', $companyId),
        ];
    }

    /**
     * A quantity is a decimal string for the same reason money is: it is stored
     * as DECIMAL(19,6) and a float cannot round-trip it. The regex is what
     * separates a quantity from the signed one an adjustment is allowed.
     *
     * @return array<string, array<int, mixed>>
     */
    private function positiveQuantityRule(): array
    {
        return ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StockMovement $movement): array
    {
        return [
            'id' => (int) $movement->getKey(),
            'ulid' => $movement->ulid,
            'company_id' => (int) $movement->company_id,
            'product_id' => (int) $movement->product_id,
            'product_sku' => $movement->product?->sku,
            'warehouse_id' => (int) $movement->warehouse_id,
            'warehouse_code' => $movement->warehouse?->code,
            'movement_type' => $movement->movement_type->value,
            'movement_date' => $movement->movement_date->toDateString(),
            'status' => $movement->status->value,
            'quantity' => $movement->quantity,
            'unit_cost' => $movement->unit_cost,
            'total_cost' => $movement->total_cost,
            'journal_id' => $movement->journal_id === null ? null : (int) $movement->journal_id,
            'reverses_movement_id' => $movement->reverses_movement_id === null
                ? null
                : (int) $movement->reverses_movement_id,
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
