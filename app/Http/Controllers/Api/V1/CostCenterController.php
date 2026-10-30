<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Enums\CostCenterStatus;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The parts of the company a plan may be split across.
 *
 * A cost centre is a dimension of the plan and not of the books: the ledger has
 * no cost centre column, so a posted journal never names one. It is guarded by
 * the budget rights rather than by a set of its own, because slicing a plan is
 * the only thing a cost centre does today.
 *
 * Like the other master data, there is no delete route: a cost centre is
 * retired by moving its status, because plan lines already written point at it.
 */
class CostCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CostCenter::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(CostCenterStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $costCenters = CostCenter::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['search'] ?? null),
                fn ($query) => $query->search($filters['search'])
            )
            ->when(
                filled($filters['status'] ?? null),
                fn ($query) => $query->where('status', $filters['status'])
            )
            ->orderBy('code')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (CostCenter $costCenter): array => $this->present($costCenter),
                $costCenters->items()
            ),
            'meta' => [
                'current_page' => $costCenters->currentPage(),
                'per_page' => $costCenters->perPage(),
                'total' => $costCenters->total(),
                'last_page' => $costCenters->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CostCenter::class);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->rules($companyId));

        $costCenter = new CostCenter([
            ...$data,
            'company_id' => $companyId,
            'status' => $data['status'] ?? CostCenterStatus::Active->value,
        ]);

        $costCenter->save();

        return response()->json(['data' => $this->present($costCenter)], 201);
    }

    public function show(Request $request, string $costCenter): JsonResponse
    {
        $record = $this->findByUlid($request, $costCenter);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    public function update(Request $request, string $costCenter): JsonResponse
    {
        $record = $this->findByUlid($request, $costCenter);

        $this->authorize('update', $record);

        $data = $request->validate($this->rules($this->companyId($request), $record));

        $record->fill($data);
        $record->save();

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(int $companyId, ?CostCenter $costCenter = null): array
    {
        $uniqueCode = Rule::unique('cost_centers', 'code')->where('company_id', $companyId);

        if ($costCenter !== null) {
            $uniqueCode = $uniqueCode->ignore($costCenter->getKey());
        }

        return [
            'code' => $costCenter === null
                ? ['required', 'string', 'max:20', $uniqueCode]
                : ['sometimes', 'string', 'max:20', $uniqueCode],
            'name' => $costCenter === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(CostCenterStatus::class)],
        ];
    }

    /**
     * Scoped to the caller's company in the same query that resolves the
     * record, so another tenant's cost centre is a 404 rather than an existence
     * oracle.
     */
    private function findByUlid(Request $request, string $ulid): CostCenter
    {
        return CostCenter::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CostCenter $costCenter): array
    {
        return [
            'id' => (int) $costCenter->getKey(),
            'ulid' => $costCenter->ulid,
            'company_id' => (int) $costCenter->company_id,
            'code' => $costCenter->code,
            'name' => $costCenter->name,
            'description' => $costCenter->description,
            'status' => $costCenter->status->value,
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
