<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Reporting\Actions\SaveBudgetPlan;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a company plans to earn and spend in a period.
 *
 * A budget is a plan and never a posting: nothing this surface does reaches
 * the ledger. That is why it is guarded by its own pair of rights rather than
 * by the report rights beside it — being trusted to read the books has never
 * meant being shown what the company intends.
 *
 * A plan is written as a whole rather than a line at a time, because it is a
 * statement about a period rather than a list that is appended to. The single
 * write route is therefore a put of the plan, and it carries no budget ULID:
 * the company writes one plan per period, so the period names it. The only
 * door onto the plan is SaveBudgetPlan, which locks the row, guards the write
 * with row_version and stamps the change into the audit chain.
 *
 * A line names an account and, optionally, the cost centre it is planned
 * under. The account must be one the period's profit could be made of — a
 * revenue or an expense account — because a plan against a balance sheet
 * account is not something the variance report could compare with anything.
 */
class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Budget::class);

        $filters = $request->validate([
            'fiscal_period' => ['sometimes', 'nullable', 'string', 'size:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $budgets = Budget::query()
            ->where('company_id', $this->companyId($request))
            ->when(
                filled($filters['fiscal_period'] ?? null),
                fn ($query) => $query->whereHas(
                    'fiscalPeriod',
                    fn ($period) => $period->where('ulid', $filters['fiscal_period'])
                )
            )
            ->with(['fiscalPeriod', 'lines.account', 'lines.costCenter'])
            ->orderByDesc('created_at')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Budget $budget): array => $this->present($budget),
                $budgets->items()
            ),
            'meta' => [
                'current_page' => $budgets->currentPage(),
                'per_page' => $budgets->perPage(),
                'total' => $budgets->total(),
                'last_page' => $budgets->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $budget): JsonResponse
    {
        $record = $this->findByUlid($request, $budget);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->present($record)]);
    }

    /**
     * Write the plan for a period, or replace the one it already has.
     *
     * Named by the fiscal period rather than by a budget ULID, because the
     * company holds one plan per period: the period the caller names is the
     * plan, whether or not one has been written for it yet. Resolving the
     * period inside the company is what makes another tenant's period a 404
     * rather than an existence oracle, and it is also what decides which right
     * answers: writing the first plan is `create`, replacing one is `manage`.
     *
     * The write right is checked before the body is read, so a request that is
     * both unauthorised and malformed is refused as forbidden rather than told
     * which fields it is missing.
     */
    public function save(Request $request, SaveBudgetPlan $save): JsonResponse
    {
        $companyId = $this->companyId($request);

        $this->authorize('create', Budget::class);

        $data = $request->validate([
            'fiscal_period' => ['required', 'string', 'size:26'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => ['present', 'array'],
            // A plan is made of the accounts the period's profit is made of,
            // restricted to the company's own chart in the same rule so naming
            // another tenant's account fails as a validation error rather than
            // silently planning against it.
            'lines.*.account_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $companyId)
                    ->whereIn('account_type', [AccountType::Revenue->value, AccountType::Expense->value]),
            ],
            'lines.*.cost_center_id' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('cost_centers', 'id')->where('company_id', $companyId),
            ],
            // Signed on purpose: a contra account is planned as a negative
            // figure, and refusing one would be refusing the plan the
            // accountant meant.
            'lines.*.amount' => ['required', 'string', 'max:32', 'regex:/^-?\d+(\.\d+)?$/'],
        ]);

        $period = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('ulid', $data['fiscal_period'])
            ->firstOrFail();

        $existing = Budget::query()
            ->where('company_id', $companyId)
            ->where('fiscal_period_id', $period->getKey())
            ->first();

        // Replacing a plan is a write against a row the company already holds,
        // so it is checked against that row rather than against the class.
        if ($existing !== null) {
            $this->authorize('manage', $existing);
        }

        $budget = $save(
            $companyId,
            $period,
            array_map(static fn (array $line): array => [
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'amount' => (string) $line['amount'],
            ], $data['lines']),
            $data['name'] ?? null,
        );

        return response()->json(
            ['data' => $this->present($budget)],
            $existing === null ? 201 : 200,
        );
    }

    /**
     * Scoped to the caller's company in the same query that resolves the
     * record, so another tenant's plan is a 404 rather than an existence
     * oracle.
     */
    private function findByUlid(Request $request, string $ulid): Budget
    {
        return Budget::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Budget $budget): array
    {
        $budget->loadMissing(['fiscalPeriod', 'lines.account', 'lines.costCenter']);

        return [
            'ulid' => $budget->ulid,
            'company_id' => (int) $budget->company_id,
            'name' => $budget->name,
            'notes' => $budget->notes,
            // Said out loud on the resource itself, because a reader of this
            // payload is one step away from the ledger and should not have to
            // know that a plan posts nothing.
            'is_a_plan' => true,
            'row_version' => (int) $budget->row_version,
            'fiscal_period' => [
                'ulid' => $budget->fiscalPeriod->ulid,
                'name' => $budget->fiscalPeriod->name,
                'period_no' => (int) $budget->fiscalPeriod->period_no,
                'starts_on' => $budget->fiscalPeriod->starts_on->toDateString(),
                'ends_on' => $budget->fiscalPeriod->ends_on->toDateString(),
            ],
            'lines' => array_map(
                static fn (BudgetLine $line): array => [
                    'account_id' => (int) $line->account_id,
                    'account_ulid' => $line->account?->ulid,
                    'account_code' => $line->account?->code,
                    'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                    'cost_center_ulid' => $line->costCenter?->ulid,
                    'amount' => (string) $line->amount,
                ],
                $budget->lines->all()
            ),
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
