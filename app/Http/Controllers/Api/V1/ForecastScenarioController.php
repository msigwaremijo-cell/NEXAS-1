<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Reporting\Actions\ForecastScenarioStore;
use App\Domain\Reporting\Enums\ForecastScenarioType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ForecastScenario;
use App\Models\ForecastScenarioVersion;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Saved forecast scenarios, their immutable history, and the low-cash threshold.
 *
 * Nothing here is accounting and nothing here posts. A saved scenario is a set
 * of assumptions — a stance, a revenue growth, an expense growth and per-account
 * adjustments — and this controller is the surface that keeps them. The
 * projection itself is still built on demand by ForecastPageQuery from the plan
 * and the posted ledger, re-stated under whatever assumptions are selected, so a
 * scenario saved here never becomes a figure in an actual report.
 *
 * The company is never taken from the request body or the query string: it comes
 * from the `company` middleware, which has already proved the caller is a member
 * of it. Every row-scoped ability is checked against the row's own company as
 * well, so a scenario belonging to another tenant is refused rather than read.
 *
 * The history is written by ForecastScenarioStore and is immutable, so there is
 * no update and no delete of a version here — only new versions, and restoring
 * an old one by writing a further version that copies it.
 */
class ForecastScenarioController extends Controller
{
    public function __construct(
        private readonly ForecastScenarioStore $store,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * The company's saved scenarios, newest name order, each with the version
     * that is currently live.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ForecastScenario::class);

        $scenarios = ForecastScenario::query()
            ->where('company_id', $this->companyId($request))
            ->with(['currentVersion.createdBy:id,name', 'createdBy:id,name'])
            ->withCount('versions')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $scenarios->map(fn (ForecastScenario $scenario): array => $this->present($scenario))->all(),
        ]);
    }

    /**
     * Save a new scenario with its first version.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ForecastScenario::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:1', 'max:120',
                Rule::unique('forecast_scenarios', 'name')->where('company_id', $companyId),
            ],
            'type' => ['required', Rule::enum(ForecastScenarioType::class)],
            'revenue_growth' => $this->growthRules(),
            'expense_growth' => $this->growthRules(),
            ...$this->adjustmentRules(),
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $scenario = $this->store->create($companyId, $this->userId($request), $this->payload($data));

        return response()->json(['data' => $this->present($this->reload($scenario))], 201);
    }

    /**
     * One scenario, with its whole version history, newest version first.
     */
    public function show(Request $request, ForecastScenario $scenario): JsonResponse
    {
        $this->authorize('view', $scenario);

        return response()->json(['data' => $this->detail($scenario)]);
    }

    /**
     * Change a scenario's assumptions by writing the next version.
     */
    public function storeVersion(Request $request, ForecastScenario $scenario): JsonResponse
    {
        $this->authorize('update', $scenario);

        $data = $request->validate([
            'name' => [
                'sometimes', 'nullable', 'string', 'min:1', 'max:120',
                Rule::unique('forecast_scenarios', 'name')
                    ->where('company_id', (int) $scenario->company_id)
                    ->ignore($scenario->getKey()),
            ],
            'type' => ['required', Rule::enum(ForecastScenarioType::class)],
            'revenue_growth' => $this->growthRules(),
            'expense_growth' => $this->growthRules(),
            ...$this->adjustmentRules(),
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $version = $this->store->revise($scenario, $this->userId($request), $this->payload($data));

        return response()->json(['data' => $this->version($version->load('createdBy:id,name'))], 201);
    }

    /**
     * Restore an old version by writing a new one that copies it.
     *
     * The version named is read and left as it was. What is written is the next
     * version, carrying the old assumptions forward, so the history shows both
     * what was saved then and that it was brought back now.
     */
    public function restore(
        Request $request,
        ForecastScenario $scenario,
        ForecastScenarioVersion $version,
    ): JsonResponse {
        $this->authorize('restoreVersion', $scenario);

        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $restored = $this->store->restore(
            $scenario,
            $version,
            $this->userId($request),
            $data['note'] ?? null,
        );

        return response()->json(['data' => $this->version($restored->load('createdBy:id,name'))], 201);
    }

    /**
     * Saved scenarios side by side, on their live versions.
     *
     * Assumptions only. This reads no projection and compares no figures: the
     * numbers a scenario produces depend on the ledger as it stands today, and
     * two saved assumptions compared as if they were results would present the
     * past as if it were still current. Opening a scenario recalculates it
     * against current actuals, and this comparison says so.
     */
    public function compare(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ForecastScenario::class);

        $filters = $request->validate([
            'scenarios' => ['required', 'array', 'min:1', 'max:5'],
            'scenarios.*' => ['required', 'string', 'size:26'],
        ]);

        $scenarios = ForecastScenario::query()
            ->where('company_id', $this->companyId($request))
            ->whereIn('ulid', $filters['scenarios'])
            ->with(['currentVersion.createdBy:id,name'])
            ->withCount('versions')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'scenarios' => $scenarios->map(fn (ForecastScenario $scenario): array => $this->present($scenario))->all(),
                'note' => 'Assumptions only, on each scenario\'s live version. No projection is compared here: a saved scenario is '
                    .'recalculated against the ledger as it stands whenever it is opened, so the figures it produces today are not the '
                    .'figures it produced when it was saved.',
            ],
        ]);
    }

    /**
     * Set the company's low-cash alert threshold.
     *
     * The blob is merged rather than replaced, so setting this does not drop any
     * other company setting, and the write is locked and audited like the other
     * company settings: what "low" means to a company is a decision, and a
     * decision that can change without a trace is one that cannot be reviewed.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', ForecastScenario::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            // Non-negative, plain decimal: the leading `\d` is what refuses a
            // minus sign, so a negative threshold cannot be set even by an
            // authorised member.
            'forecast_low_cash_threshold' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);

        $threshold = Money::of($data['forecast_low_cash_threshold'])->toDecimal();

        DB::transaction(function () use ($companyId, $threshold): void {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            $settings = $company->settings ?? [];
            $before = $settings['forecast_low_cash_threshold'] ?? null;

            $settings['forecast_low_cash_threshold'] = $threshold;
            $company->settings = $settings;
            $company->save();

            if ((string) $before !== $threshold) {
                $this->audit->record(
                    'company.forecast_settings.updated',
                    $company,
                    ['forecast_low_cash_threshold' => $before],
                    ['forecast_low_cash_threshold' => $threshold],
                    (int) $company->getKey(),
                );
            }
        });

        return response()->json([
            'data' => ['forecast_low_cash_threshold' => $threshold],
        ]);
    }

    /**
     * The assumptions a scenario's write was asked for, normalised.
     *
     * Adjustments are keyed by account so a repeated account resolves to one
     * figure, and the growth rates fall back to zero — the projection the
     * company actually expects — when the caller named none.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'type' => (string) $data['type'],
            'revenue_growth' => (string) ($data['revenue_growth'] ?? '0'),
            'expense_growth' => (string) ($data['expense_growth'] ?? '0'),
            'adjustments' => $this->adjustments($data['adjustments'] ?? []),
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function adjustments(array $rows): array
    {
        $adjustments = [];

        foreach ($rows as $row) {
            $adjustments[(int) $row['account_id']] = (string) $row['amount'];
        }

        return $adjustments;
    }

    /**
     * A growth rate is a percentage as a plain decimal, bounded by the envelope
     * in configuration. `numeric` is stated before `between` on purpose: without
     * it `between` measures a string's length, so `5000` would slip past a
     * ceiling of 1000 by being four characters long.
     *
     * @return list<string>
     */
    private function growthRules(): array
    {
        $max = (string) config('nexas.forecast.max_growth_percent', '1000');

        return [
            'sometimes', 'nullable',
            'numeric',
            'regex:/^-?\d+(\.\d{1,4})?$/',
            'between:-'.$max.','.$max,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function adjustmentRules(): array
    {
        return [
            'adjustments' => ['sometimes', 'array', 'max:'.(int) config('nexas.forecast.max_adjustments', 50)],
            'adjustments.*.account_id' => ['required', 'integer', 'min:1'],
            'adjustments.*.amount' => ['required', 'regex:/^-?\d+(\.\d{1,4})?$/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ForecastScenario $scenario): array
    {
        return [
            'ulid' => $scenario->ulid,
            'name' => (string) $scenario->name,
            'type' => $scenario->type->value,
            'type_label' => $scenario->type->label(),
            'current_version_no' => (int) $scenario->current_version_no,
            'version_count' => (int) ($scenario->versions_count ?? 0),
            'current_version' => $scenario->currentVersion === null ? null : $this->version($scenario->currentVersion),
            'created_by' => $this->author($scenario->createdBy),
            'created_at' => $scenario->created_at?->toIso8601String(),
            'updated_at' => $scenario->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(ForecastScenario $scenario): array
    {
        // The count is read the same way the list reads it, so one scenario is
        // described identically whether it arrives from the index or on its own.
        $scenario->loadCount('versions');

        $scenario->load([
            'createdBy:id,name',
            'versions.createdBy:id,name',
        ]);

        return $this->present($scenario) + [
            'versions' => $scenario->versions
                ->sortByDesc('version_no')
                ->values()
                ->map(fn (ForecastScenarioVersion $version): array => $this->version($version))
                ->all(),
            'recalculated_with_current_actuals' => true,
            'note' => 'A saved scenario is a set of assumptions, not a result. Opening one recalculates the projection against the ledger '
                .'as it stands, so the figures it produces today come from today\'s posted actuals and not from when it was saved.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function version(ForecastScenarioVersion $version): array
    {
        return [
            'ulid' => $version->ulid,
            'version_no' => (int) $version->version_no,
            'type' => $version->type->value,
            'revenue_growth' => (string) $version->revenue_growth,
            'expense_growth' => (string) $version->expense_growth,
            'adjustments' => $version->adjustments ?? [],
            'note' => $version->note,
            'created_by' => $this->author($version->createdBy),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function author(?User $user): ?array
    {
        return $user === null ? null : ['id' => (int) $user->getKey(), 'name' => (string) $user->name];
    }

    private function reload(ForecastScenario $scenario): ForecastScenario
    {
        return ForecastScenario::query()
            ->whereKey($scenario->getKey())
            ->with(['currentVersion.createdBy:id,name', 'createdBy:id,name'])
            ->withCount('versions')
            ->firstOrFail();
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getKey();
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
