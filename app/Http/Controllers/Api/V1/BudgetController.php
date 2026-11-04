<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Approvals\Actions\DecideApprovalRequest;
use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Domain\Reporting\Actions\BudgetVersionLifecycle;
use App\Domain\Reporting\Actions\BudgetVersionStore;
use App\Domain\Reporting\Actions\SaveBudgetPlan;
use App\Domain\Reporting\Exceptions\BudgetStateException;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\BudgetVersionLine;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
 *
 * The routes below the period plan are the plan's life. A plan for a fiscal
 * year is a version that is drafted, raised for a decision, agreed to and
 * closed, and the version routes are how a member moves it. Nothing here posts
 * a journal at any stage: approving and locking a plan records a decision, not
 * a movement, and the amounts still live only in the plan.
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
     * The versions of one fiscal year's plan, newest first.
     *
     * The history the version panel reads. It names the fiscal year rather than
     * the version because a member asks "what has this year's plan been?", and
     * resolving the year inside the company is what makes another tenant's year
     * a 404 rather than an existence oracle.
     */
    public function versions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', BudgetVersion::class);

        $companyId = $this->companyId($request);

        $filters = $request->validate([
            'fiscal_year' => ['required', 'string', 'size:26'],
        ]);

        $year = $this->findYear($companyId, $filters['fiscal_year']);

        $versions = BudgetVersion::query()
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $year->getKey())
            ->orderByDesc('version_no')
            ->get();

        return response()->json([
            'data' => $versions->map(fn (BudgetVersion $version): array => $this->versionSummary($version))->all(),
            'fiscal_year' => $this->presentYear($year),
        ]);
    }

    /**
     * One version with its whole plan: every month, account and cost centre.
     */
    public function showVersion(Request $request, BudgetVersion $version): JsonResponse
    {
        $this->authorize('view', $version);

        return response()->json(['data' => $this->presentVersion($this->loadVersion($version))]);
    }

    /**
     * Write the plan for a fiscal year, as a new version or a rewrite of the
     * draft on the table.
     *
     * Whether the write starts a version or rewrites one is decided by the plan
     * itself, not by the caller: a draft is the only version that is edited in
     * place, a plan that has been agreed to is superseded by the next version
     * and a plan that has been raised or locked refuses the write outright. The
     * reason the caller sends is required the moment a version is being
     * superseded, because a change to a signed-off plan has to say why.
     *
     * The write right is checked before the body is read, so a request that is
     * both unauthorised and malformed is refused as forbidden rather than told
     * which fields it is missing.
     */
    public function saveVersion(Request $request, BudgetVersionStore $store): JsonResponse
    {
        $this->authorize('create', BudgetVersion::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'fiscal_year' => ['required', 'string', 'size:26'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_row_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'lines' => ['present', 'array'],
            'lines.*.fiscal_period' => ['required', 'string', 'size:26'],
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
            'lines.*.amount' => ['required', 'string', 'max:32', 'regex:/^-?\d+(\.\d+)?$/'],
        ]);

        $year = $this->findYear($companyId, $data['fiscal_year']);

        $existing = BudgetVersion::query()
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $year->getKey())
            ->orderByDesc('version_no')
            ->first();

        $periodIds = $this->resolvePeriods($companyId, $year, array_column($data['lines'], 'fiscal_period'));

        $lines = array_map(static function (array $line) use ($periodIds): array {
            if (! isset($periodIds[$line['fiscal_period']])) {
                throw ValidationException::withMessages([
                    'lines' => sprintf(
                        'The fiscal period %s is not a period of the fiscal year named, so it cannot be planned for here.',
                        $line['fiscal_period'],
                    ),
                ]);
            }

            return [
                'fiscal_period_id' => $periodIds[$line['fiscal_period']],
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'amount' => (string) $line['amount'],
            ];
        }, $data['lines']);

        $version = $store->save(
            $companyId,
            $this->userId($request),
            $year,
            $lines,
            $data['name'] ?? null,
            $data['reason'] ?? null,
            isset($data['expected_row_version']) ? (int) $data['expected_row_version'] : null,
        );

        // A rewrite of the draft on the table answers with the version that was
        // already there; anything else is a version being raised for the first
        // time. The distinction is what tells the caller whether a new row was
        // created, so it is read from the version numbers rather than guessed.
        $started = $existing === null || (int) $version->version_no !== (int) $existing->version_no;

        return response()->json(
            ['data' => $this->presentVersion($this->loadVersion($version))],
            $started ? 201 : 200,
        );
    }

    /**
     * Raise a draft plan for a decision.
     *
     * The decision itself is not made here: it waits in the approvals queue,
     * where the no-self-approval rule lives. This only puts it there.
     */
    public function submitVersion(
        Request $request,
        BudgetVersion $version,
        BudgetVersionLifecycle $lifecycle,
    ): JsonResponse {
        $this->authorize('submit', $version);

        $submitted = $lifecycle->submit(
            $version,
            $this->userId($request),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentVersion($this->loadVersion($submitted))]);
    }

    /**
     * Close an approved plan. A separate act from approving it, so the member
     * who agrees to a plan need not be the member who declares it settled.
     */
    public function lockVersion(
        Request $request,
        BudgetVersion $version,
        BudgetVersionLifecycle $lifecycle,
    ): JsonResponse {
        $this->authorize('lock', $version);

        $locked = $lifecycle->lockVersion(
            $version,
            $this->userId($request),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentVersion($this->loadVersion($locked))]);
    }

    /**
     * Reopen a locked plan so the next version may be started from it.
     */
    public function unlockVersion(
        Request $request,
        BudgetVersion $version,
        BudgetVersionLifecycle $lifecycle,
    ): JsonResponse {
        $this->authorize('unlock', $version);

        $unlocked = $lifecycle->unlock(
            $version,
            $this->userId($request),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentVersion($this->loadVersion($unlocked))]);
    }

    /**
     * Agree to a plan that is waiting on a decision.
     *
     * The decision is the queue's, not this surface's: the plan's approval
     * request is handed to the same action the approvals screen uses, so the
     * no-self-approval rule, the finality of a decision and the idempotency
     * behind it are exactly the queue's. What is different here is only the
     * door — this one is guarded by `budget.approve`, which the Owner holds, so
     * the Owner can agree to a plan without holding the queue's decision right.
     */
    public function approveVersion(
        Request $request,
        BudgetVersion $version,
        DecideApprovalRequest $decide,
    ): JsonResponse {
        $this->authorize('approve', $version);

        $record = $this->pendingApproval($request, $version);

        $decide->approve($record, $this->userId($request), $this->requireIdempotencyKey($request));

        return response()->json(['data' => $this->presentVersion($this->loadVersion($version))]);
    }

    /**
     * Send a plan back with the reason it was not accepted. The plan returns to
     * Draft and the member who wrote it can correct it; the reason is kept on
     * the version, because it is the only thing they are given to act on.
     */
    public function rejectVersion(
        Request $request,
        BudgetVersion $version,
        DecideApprovalRequest $decide,
    ): JsonResponse {
        $this->authorize('approve', $version);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $record = $this->pendingApproval($request, $version);

        $decide->reject($record, $data['reason'], $this->userId($request), $this->requireIdempotencyKey($request));

        return response()->json(['data' => $this->presentVersion($this->loadVersion($version))]);
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

    /**
     * The fiscal year named, read inside the caller's company so another
     * tenant's year is a 404 rather than an existence oracle.
     */
    private function findYear(int $companyId, string $ulid): FiscalYear
    {
        return FiscalYear::query()
            ->where('company_id', $companyId)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * Turn the periods a plan names from their public ids into the keys the
     * version's lines are written with, read inside the company and the year so
     * a period from another tenant or another year cannot be planned against.
     *
     * @param  list<string>  $ulids
     * @return array<string, int>
     */
    private function resolvePeriods(int $companyId, FiscalYear $year, array $ulids): array
    {
        $ids = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $year->getKey())
            ->whereIn('ulid', array_values(array_unique($ulids)))
            ->pluck('id', 'ulid')
            ->all();

        return array_map(static fn ($id): int => (int) $id, $ids);
    }

    private function loadVersion(BudgetVersion $version): BudgetVersion
    {
        return BudgetVersion::query()
            ->whereKey($version->getKey())
            ->with(['fiscalYear', 'lines.account', 'lines.costCenter', 'lines.fiscalPeriod'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentYear(FiscalYear $year): array
    {
        return [
            'ulid' => $year->ulid,
            'name' => $year->name,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
        ];
    }

    /**
     * A version's header, without its lines: what the history panel lists.
     *
     * @return array<string, mixed>
     */
    private function versionSummary(BudgetVersion $version): array
    {
        return [
            'ulid' => $version->ulid,
            'version_no' => (int) $version->version_no,
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            'name' => $version->name,
            'reason' => $version->reason,
            'row_version' => (int) $version->row_version,
            'is_editable' => $version->status->isEditable(),
            'created_by_user_id' => $this->userIdOrNull($version->created_by_user_id),
            'submitted_by_user_id' => $this->userIdOrNull($version->submitted_by_user_id),
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'decided_by_user_id' => $this->userIdOrNull($version->decided_by_user_id),
            'decided_at' => $version->decided_at?->toIso8601String(),
            'decision_reason' => $version->decision_reason,
            'locked_by_user_id' => $this->userIdOrNull($version->locked_by_user_id),
            'locked_at' => $version->locked_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }

    /**
     * A version with its whole plan: every month, account and cost centre,
     * ready for the twelve-column grid.
     *
     * @return array<string, mixed>
     */
    private function presentVersion(BudgetVersion $version): array
    {
        $version->loadMissing(['fiscalYear', 'lines.account', 'lines.costCenter', 'lines.fiscalPeriod']);

        return [
            'ulid' => $version->ulid,
            'company_id' => (int) $version->company_id,
            'version_no' => (int) $version->version_no,
            'status' => $version->status->value,
            'status_label' => $version->status->label(),
            'name' => $version->name,
            'reason' => $version->reason,
            'row_version' => (int) $version->row_version,
            // Said out loud on the resource itself: a reader of this payload is
            // one step away from the ledger and should not have to know that a
            // plan posts nothing.
            'is_a_plan' => true,
            'is_editable' => $version->status->isEditable(),
            'fiscal_year' => $version->fiscalYear === null ? null : $this->presentYear($version->fiscalYear),
            'created_by_user_id' => $this->userIdOrNull($version->created_by_user_id),
            'submitted_by_user_id' => $this->userIdOrNull($version->submitted_by_user_id),
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'decided_by_user_id' => $this->userIdOrNull($version->decided_by_user_id),
            'decided_at' => $version->decided_at?->toIso8601String(),
            'decision_reason' => $version->decision_reason,
            'locked_by_user_id' => $this->userIdOrNull($version->locked_by_user_id),
            'locked_at' => $version->locked_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
            'lines' => array_map(
                static fn (BudgetVersionLine $line): array => [
                    'fiscal_period_id' => (int) $line->fiscal_period_id,
                    'fiscal_period_ulid' => $line->fiscalPeriod?->ulid,
                    'fiscal_period_no' => $line->fiscalPeriod === null ? null : (int) $line->fiscalPeriod->period_no,
                    'account_id' => (int) $line->account_id,
                    'account_ulid' => $line->account?->ulid,
                    'account_code' => $line->account?->code,
                    'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                    'cost_center_ulid' => $line->costCenter?->ulid,
                    'amount' => (string) $line->amount,
                ],
                $version->lines->all()
            ),
        ];
    }

    private function userIdOrNull(mixed $id): ?int
    {
        return $id === null ? null : (int) $id;
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getKey();
    }

    /**
     * The key a caller may name to make a move replayable. Optional here
     * because the state machine already refuses a repeated act; a caller that
     * does send one gets the first answer back rather than a conflict.
     */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * A decision has to name the attempt it answers, so a retried approval
     * cannot decide the same plan twice.
     */
    private function requireIdempotencyKey(Request $request): string
    {
        return $this->idempotencyKey($request) ?? throw ApprovalsException::idempotencyKeyRequired();
    }

    /**
     * The plan's request in the approvals queue, refused as a conflict when
     * there is nothing waiting on a decision. Read inside the company so
     * another tenant's request cannot be reached.
     */
    private function pendingApproval(Request $request, BudgetVersion $version): ApprovalRequest
    {
        $record = ApprovalRequest::query()
            ->where('company_id', $this->companyId($request))
            ->where('document_type', ApprovalDocumentType::Budget)
            ->where('document_id', $version->getKey())
            ->first();

        if ($record === null || $record->status !== ApprovalStatus::Pending) {
            throw BudgetStateException::notPending($version);
        }

        return $record;
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
