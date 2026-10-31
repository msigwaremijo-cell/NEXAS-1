<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assets\Actions\DisposeAsset;
use App\Domain\Assets\Actions\ReverseDepreciation;
use App\Domain\Assets\Actions\ReverseDisposal;
use App\Domain\Assets\Actions\RunDepreciation;
use App\Domain\Assets\Actions\SaveAsset;
use App\Domain\Assets\Actions\SaveAssetCategory;
use App\Domain\Assets\Enums\AssetCategoryStatus;
use App\Domain\Assets\Enums\AssetStatus;
use App\Domain\Assets\Services\DepreciationSchedule;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationLine;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The fixed-asset register, and the two things that move the ledger on its
 * behalf: a period's depreciation and a disposal.
 *
 * The register is what the company holds; the ledger is what it is worth. This
 * surface keeps the two in step by never letting a figure be written in one
 * without the other — entering an asset posts its cost, a run posts the month's
 * charge, and a disposal takes the asset back out — so a reader can always
 * reconcile the register against the accounts. That is also why nothing here
 * deletes: an asset leaves the register by being disposed of, and a disposal is
 * corrected by being reversed.
 *
 * A depreciation run is named by its fiscal period rather than by a list of
 * assets, because a period's charge is a period's figure: the run writes one
 * journal covering every asset in service, and running the same period twice is
 * refused rather than allowed to double the charge. The straight-line method it
 * uses is an accounting method and not a claim about Tanzanian tax
 * depreciation; the note returned beside it says so, and the useful lives the
 * company sets need an accountant's confirmation before the figures are relied
 * on.
 *
 * Every write route takes an optional Idempotency-Key header and every posting
 * refuses a period or year the calendar has closed. A disposal that receives
 * money names the account it was received into; a disposal that receives
 * nothing names none, because there is no second side to give it.
 */
class AssetController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    public function categories(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetCategory::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(AssetCategoryStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $categories = AssetCategory::query()
            ->where('company_id', $this->companyId($request))
            ->with([
                'assetAccount',
                'accumulatedDepreciationAccount',
                'depreciationExpenseAccount',
                'disposalAccount',
            ])
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->search($filters['search']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->orderBy('code')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (AssetCategory $category): array => $this->presentCategory($category),
                $categories->items()
            ),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'last_page' => $categories->lastPage(),
            ],
        ]);
    }

    public function storeCategory(Request $request, SaveAssetCategory $save): JsonResponse
    {
        $this->authorize('create', AssetCategory::class);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->categoryRules($companyId));

        $category = $save($companyId, $data);

        return response()->json(['data' => $this->presentCategory($category->load([
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'disposalAccount',
        ]))], 201);
    }

    public function showCategory(Request $request, string $assetCategory): JsonResponse
    {
        $category = $this->findCategory($request, $assetCategory);

        $this->authorize('view', $category);

        return response()->json(['data' => $this->presentCategory($category)]);
    }

    public function updateCategory(Request $request, string $assetCategory, SaveAssetCategory $save): JsonResponse
    {
        $category = $this->findCategory($request, $assetCategory);

        $this->authorize('update', $category);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->categoryRules($companyId, $category));

        $updated = $save(
            $companyId,
            $data,
            $category,
            $request->integer('row_version') ?: null,
        );

        return response()->json(['data' => $this->presentCategory($updated->load([
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'disposalAccount',
        ]))]);
    }

    /*
    |--------------------------------------------------------------------------
    | Assets
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(AssetStatus::class)],
            'asset_category' => ['sometimes', 'nullable', 'string', 'size:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $assets = Asset::query()
            ->where('company_id', $this->companyId($request))
            ->with('category')
            ->when(filled($filters['search'] ?? null), fn ($query) => $query->search($filters['search']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(
                filled($filters['asset_category'] ?? null),
                fn ($query) => $query->whereHas(
                    'category',
                    fn ($category) => $category->where('ulid', $filters['asset_category'])
                )
            )
            ->orderBy('code')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => array_map(
                fn (Asset $asset): array => $this->presentAsset($asset),
                $assets->items()
            ),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
                'last_page' => $assets->lastPage(),
            ],
            // Said out loud rather than left to be assumed: the schedule
            // returned is the accounting method, not a tax computation.
            'depreciation' => [
                'method' => DepreciationSchedule::METHOD,
                'note' => DepreciationSchedule::METHOD_NOTE,
            ],
        ]);
    }

    public function storeAsset(Request $request, SaveAsset $save): JsonResponse
    {
        $this->authorize('create', Asset::class);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->assetRules($companyId));

        $asset = $save(
            $companyId,
            $data,
            null,
            null,
            $request->user()?->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentAsset($asset->load('category'))], 201);
    }

    public function showAsset(Request $request, string $asset): JsonResponse
    {
        $record = $this->findAsset($request, $asset);

        $this->authorize('view', $record);

        return response()->json(['data' => $this->presentAsset($record)]);
    }

    public function updateAsset(Request $request, string $asset, SaveAsset $save): JsonResponse
    {
        $record = $this->findAsset($request, $asset);

        $this->authorize('update', $record);

        $companyId = $this->companyId($request);

        $data = $request->validate($this->assetRules($companyId, $record));

        $updated = $save(
            $companyId,
            $data,
            $record,
            $request->integer('row_version') ?: null,
            $request->user()?->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentAsset($updated->load('category'))]);
    }

    /*
    |--------------------------------------------------------------------------
    | Depreciation
    |--------------------------------------------------------------------------
    */

    /**
     * Charge one fiscal period across every asset in service.
     *
     * Named by the period because that is what a charge is: the assets are how
     * it is arrived at, not what it is asked for. A second run for the same
     * period is refused, and so is a period or year the calendar has closed.
     */
    public function runDepreciation(Request $request, RunDepreciation $run): JsonResponse
    {
        $this->authorize('depreciate', Asset::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'fiscal_period' => ['required', 'string', 'size:26'],
        ]);

        $period = $this->period($companyId, $data['fiscal_period']);

        $journal = $run(
            $companyId,
            $period,
            $request->user()?->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentJournal($journal)], 201);
    }

    /**
     * Undo a period's run by reversing the journal it wrote.
     *
     * Not a delete: the journal takes its own number and the original moves to
     * reversed, and the assets get back what the run charged them.
     */
    public function reverseDepreciation(Request $request, ReverseDepreciation $reverse): JsonResponse
    {
        $this->authorize('depreciate', Asset::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'fiscal_period' => ['required', 'string', 'size:26'],
            'posting_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $period = $this->period($companyId, $data['fiscal_period']);

        $journal = $reverse(
            $companyId,
            $period,
            $data['posting_date'] ?? null,
            $data['memo'] ?? null,
            $request->user()?->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentJournal($journal)], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Disposal
    |--------------------------------------------------------------------------
    */

    /**
     * Take an asset out of the books at a date for a proceeds amount.
     *
     * Depreciation is brought current for the period the disposal falls in
     * first, so the figures the entry removes are the ones that should be in the
     * books at that date. A disposed asset is then history: it is not
     * depreciated, not moved and not disposed of twice, and the only correction
     * is the reversal below.
     */
    public function dispose(Request $request, string $asset, DisposeAsset $dispose): JsonResponse
    {
        $record = $this->findAsset($request, $asset);

        $this->authorize('dispose', $record);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            // Signed on purpose, so a negative figure reaches the domain and is
            // refused in the language of disposals rather than of validation.
            'proceeds' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^-?\d+(\.\d+)?$/'],
            'proceeds_account_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
        ]);

        $journal = $dispose(
            $companyId,
            $record,
            $data['disposal_date'],
            $data['proceeds'] ?? '0',
            $data['proceeds_account_id'] ?? null,
            $request->user()?->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentJournal($journal)], 201);
    }

    public function reverseDisposal(Request $request, string $asset, ReverseDisposal $reverse): JsonResponse
    {
        $record = $this->findAsset($request, $asset);

        $this->authorize('reverseDisposal', $record);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'posting_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'memo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $journal = $reverse(
            $companyId,
            $record,
            $data['posting_date'] ?? null,
            $data['memo'] ?? null,
            $request->user()?->getKey(),
            $this->idempotencyKey($request),
        );

        return response()->json(['data' => $this->presentJournal($journal)], 201);
    }

    /**
     * What the register holds against the accounts it is carried in.
     *
     * Read from the same two sides the ledger would be read from, so a reader
     * can see the register tie out rather than take it on trust: only assets in
     * service are counted, because a draft has not reached the books and a
     * disposal has taken its asset back out of them.
     */
    public function register(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $companyId = $this->companyId($request);

        $assets = Asset::query()
            ->where('company_id', $companyId)
            ->inService()
            ->get();

        $cost = $assets->reduce(
            fn (string $carry, Asset $asset): string => bcadd($carry, (string) $asset->acquisition_cost, 4),
            '0.0000'
        );

        $accumulated = $assets->reduce(
            fn (string $carry, Asset $asset): string => bcadd($carry, (string) $asset->accumulated_depreciation, 4),
            '0.0000'
        );

        $byCategory = [];

        foreach ($assets as $asset) {
            $key = (int) $asset->asset_category_id;

            $byCategory[$key] ??= [
                'asset_category_id' => $key,
                'asset_count' => 0,
                'cost' => '0.0000',
                'accumulated_depreciation' => '0.0000',
            ];

            $byCategory[$key]['asset_count']++;
            $byCategory[$key]['cost'] = bcadd($byCategory[$key]['cost'], (string) $asset->acquisition_cost, 4);
            $byCategory[$key]['accumulated_depreciation'] = bcadd(
                $byCategory[$key]['accumulated_depreciation'],
                (string) $asset->accumulated_depreciation,
                4
            );
        }

        return response()->json(['data' => [
            'asset_count' => $assets->count(),
            'cost' => $cost,
            'accumulated_depreciation' => $accumulated,
            'net_book_value' => bcsub($cost, $accumulated, 4),
            'by_category' => array_values($byCategory),
        ]]);
    }

    /*
    |--------------------------------------------------------------------------
    | Lookups and shape
    |--------------------------------------------------------------------------
    */

    /**
     * Scoped to the caller's company in the same query that resolves the
     * record, so another tenant's category is a 404 rather than an existence
     * oracle.
     */
    private function findCategory(Request $request, string $ulid): AssetCategory
    {
        return AssetCategory::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->with([
                'assetAccount',
                'accumulatedDepreciationAccount',
                'depreciationExpenseAccount',
                'disposalAccount',
            ])
            ->firstOrFail();
    }

    private function findAsset(Request $request, string $ulid): Asset
    {
        return Asset::query()
            ->where('company_id', $this->companyId($request))
            ->where('ulid', $ulid)
            ->with('category')
            ->firstOrFail();
    }

    private function period(int $companyId, string $ulid): FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRules(int $companyId, ?AssetCategory $category = null): array
    {
        $unique = Rule::unique('asset_categories', 'code')
            ->where('company_id', $companyId)
            ->ignore($category?->getKey());

        $account = static fn (): array => [
            'required', 'integer',
            Rule::exists('accounts', 'id')->where('company_id', $companyId),
        ];

        return [
            'code' => $category === null
                ? ['required', 'string', 'max:32', $unique]
                : ['sometimes', 'string', 'max:32', $unique],
            'name' => $category === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'asset_account_id' => $category === null ? $account() : ['sometimes', ...$account()],
            'accumulated_depreciation_account_id' => $category === null ? $account() : ['sometimes', ...$account()],
            'depreciation_expense_account_id' => $category === null ? $account() : ['sometimes', ...$account()],
            'disposal_account_id' => $category === null ? $account() : ['sometimes', ...$account()],
            'default_useful_life_months' => $category === null
                ? ['required', 'integer', 'min:1', 'max:1200']
                : ['sometimes', 'integer', 'min:1', 'max:1200'],
            'status' => ['sometimes', 'nullable', Rule::enum(AssetCategoryStatus::class)],
            'row_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRules(int $companyId, ?Asset $asset = null): array
    {
        $unique = Rule::unique('assets', 'code')
            ->where('company_id', $companyId)
            ->ignore($asset?->getKey());

        // Money arrives as a decimal string and is compared as one: a negative
        // figure is left to the domain, which refuses it in the language of
        // assets rather than as a type error.
        $money = ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^-?\d+(\.\d+)?$/'];

        return [
            'asset_category_id' => $asset === null
                ? ['required', 'integer', Rule::exists('asset_categories', 'id')->where('company_id', $companyId)]
                : ['sometimes', 'integer', Rule::exists('asset_categories', 'id')->where('company_id', $companyId)],
            'code' => $asset === null
                ? ['required', 'string', 'max:32', $unique]
                : ['sometimes', 'string', 'max:32', $unique],
            'name' => $asset === null
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'nullable', Rule::enum(AssetStatus::class)],
            'acquisition_cost' => $asset === null
                ? ['required', 'string', 'max:32', 'regex:/^-?\d+(\.\d+)?$/']
                : $money,
            'salvage_value' => $money,
            'useful_life_months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1200'],
            'in_service_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'acquisition_account_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],
            'row_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCategory(AssetCategory $category): array
    {
        return [
            'id' => (int) $category->getKey(),
            'ulid' => $category->ulid,
            'company_id' => (int) $category->company_id,
            'code' => $category->code,
            'name' => $category->name,
            'description' => $category->description,
            'status' => $category->status->value,
            'default_useful_life_months' => (int) $category->default_useful_life_months,
            'row_version' => (int) $category->row_version,
            'accounts' => [
                'asset' => $this->presentAccount($category->assetAccount),
                'accumulated_depreciation' => $this->presentAccount($category->accumulatedDepreciationAccount),
                'depreciation_expense' => $this->presentAccount($category->depreciationExpenseAccount),
                'disposal' => $this->presentAccount($category->disposalAccount),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAsset(Asset $asset): array
    {
        return [
            'id' => (int) $asset->getKey(),
            'ulid' => $asset->ulid,
            'company_id' => (int) $asset->company_id,
            'asset_category_id' => (int) $asset->asset_category_id,
            'category' => $asset->relationLoaded('category') && $asset->category !== null
                ? ['ulid' => $asset->category->ulid, 'code' => $asset->category->code, 'name' => $asset->category->name]
                : null,
            'code' => $asset->code,
            'name' => $asset->name,
            'description' => $asset->description,
            'status' => $asset->status->value,
            'acquisition_cost' => (string) $asset->acquisition_cost,
            'salvage_value' => (string) $asset->salvage_value,
            'accumulated_depreciation' => (string) $asset->accumulated_depreciation,
            'net_book_value' => $asset->netBookValue()->toDecimal(),
            'useful_life_months' => (int) $asset->useful_life_months,
            'in_service_date' => $asset->in_service_date?->toDateString(),
            'depreciated_through' => $asset->depreciated_through?->toDateString(),
            'disposed_on' => $asset->disposed_on?->toDateString(),
            'disposal_proceeds' => $asset->disposal_proceeds === null ? null : (string) $asset->disposal_proceeds,
            'row_version' => (int) $asset->row_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAccount(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => (int) $account->getKey(),
            'ulid' => $account->ulid,
            'code' => $account->code,
            'name' => $account->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentJournal(Journal $journal): array
    {
        return [
            'id' => (int) $journal->getKey(),
            'ulid' => $journal->ulid,
            'journal_no' => $journal->journal_no,
            'journal_type' => $journal->journal_type->value,
            'status' => $journal->status->value,
            'posting_date' => $journal->posting_date?->toDateString(),
            'reference' => $journal->reference,
            'memo' => $journal->memo,
            'total_debit' => (string) $journal->total_debit,
            'total_credit' => (string) $journal->total_credit,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            // What the run charged, per asset, when the journal is a run's. The
            // table is what a reversal reads, so it is also what a reader of a
            // run should be shown.
            'asset_charges' => AssetDepreciationLine::query()
                ->where('journal_id', $journal->getKey())
                ->with('asset')
                ->get()
                ->map(static fn (AssetDepreciationLine $line): array => [
                    'asset_ulid' => $line->asset?->ulid,
                    'asset_code' => $line->asset?->code,
                    'amount' => (string) $line->amount,
                ])
                ->all(),
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }
}
