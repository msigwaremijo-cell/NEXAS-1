<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Enums\StockItemStatus;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The places stock is kept.
 *
 * A warehouse is not a label on a movement: a FIFO layer belongs to the
 * warehouse that received it, so the quantity on hand of one item in one place
 * is answered from that warehouse's own layers. Moving stock between two of
 * them is what a transfer is for, and it carries the cost with it rather than
 * revaluing the goods at the far end.
 *
 * `is_default` is singular by intent, so naming a new default clears the flag
 * on the company's other warehouses rather than leaving two candidates for a
 * screen to pick from.
 */
class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(StockItemStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $warehouses = Warehouse::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->search($filters['search'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Warehouse $warehouse): array => $this->present($warehouse),
                $warehouses->items()
            ),
            'meta' => [
                'current_page' => $warehouses->currentPage(),
                'per_page' => $warehouses->perPage(),
                'total' => $warehouses->total(),
                'last_page' => $warehouses->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Warehouse::class);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->rules($companyId));

        $warehouse = new Warehouse([
            ...$data,
            'company_id' => $companyId,
            'is_default' => $data['is_default'] ?? false,
            'status' => $data['status'] ?? StockItemStatus::Active->value,
        ]);

        DB::transaction(function () use ($warehouse, $companyId): void {
            $warehouse->save();

            if ($warehouse->is_default) {
                $this->clearOtherDefaults($companyId, (int) $warehouse->getKey());
            }
        });

        return response()->json(['data' => $this->present($warehouse)], 201);
    }

    public function show(Request $request, string $warehouse): JsonResponse
    {
        $record = $this->findByCode($request, $warehouse);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    public function update(Request $request, string $warehouse): JsonResponse
    {
        $record = $this->findByCode($request, $warehouse);

        $this->authorize('update', $record);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->rules($companyId, $record));

        $record->fill($data);

        DB::transaction(function () use ($record, $companyId): void {
            $record->save();

            if ($record->is_default) {
                $this->clearOtherDefaults($companyId, (int) $record->getKey());
            }
        });

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * A warehouse is the only one in its company that may be the default, so
     * promoting one demotes whichever held the flag.
     */
    private function clearOtherDefaults(int $companyId, int $keepId): void
    {
        Warehouse::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $keepId)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(int $companyId, ?Warehouse $warehouse = null): array
    {
        $uniqueCode = Rule::unique('warehouses', 'code')->where('company_id', $companyId);

        if ($warehouse !== null) {
            $uniqueCode = $uniqueCode->ignore($warehouse->getKey());
        }

        return [
            'code' => $warehouse === null
                ? ['required', 'string', 'max:20', $uniqueCode]
                : ['sometimes', 'string', 'max:20', $uniqueCode],
            'name' => $warehouse === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'string', 'max:255'],
            'branch_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(StockItemStatus::class)],
        ];
    }

    /**
     * Scoped to the caller's company in the same query that resolves the
     * record, so another tenant's warehouse is a 404 rather than an existence
     * oracle. A warehouse has no public identifier of its own in this
     * surface's URLs, so the company-unique code is what names it.
     */
    private function findByCode(Request $request, string $code): Warehouse
    {
        return Warehouse::query()
            ->where('company_id', $this->companyId($request))
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Warehouse $warehouse): array
    {
        return [
            'id' => (int) $warehouse->getKey(),
            'ulid' => $warehouse->ulid,
            'company_id' => (int) $warehouse->company_id,
            'branch_id' => $warehouse->branch_id === null ? null : (int) $warehouse->branch_id,
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'is_default' => (bool) $warehouse->is_default,
            'status' => $warehouse->status->value,
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
