<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Assets\Enums\AssetStatus;
use App\Domain\Assets\Exceptions\AssetsException;
use App\Domain\Assets\Exceptions\AssetStateException;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Creates or amends one item of the fixed-asset register, and posts its cost
 * into the ledger when it is placed in service.
 *
 * The register and the ledger are kept in step by one rule: an asset's cost is
 * a posting, not a number written on a row and trusted. A draft is whatever the
 * caller is still deciding — its cost, its life and its salvage value are all
 * editable, because nothing has reached the books. Entering it into service is
 * what posts the cost, Dr the category's asset account against an account the
 * caller names, and that posting is why the cost is fixed from then on: an
 * editable cost would leave the register disagreeing with the account it was
 * debited to.
 *
 * Two things therefore lock by degrees rather than at once:
 *
 *   - the cost locks the moment the asset enters service, because the journal
 *     has been written;
 *   - the useful life, the salvage value and the in-service date lock only once
 *     a depreciation run has actually charged against them, because until then
 *     changing them restates nothing. The test is the asset's own accumulated
 *     depreciation, so a life corrected in the same month the asset was entered
 *     is allowed and a life corrected after the first run is not.
 *
 * Status moves draft -> in_service -> disposed and never backwards. A disposal
 * is refused here in favour of its own route, because the route is what removes
 * the cost from the books; setting the status alone would leave the register
 * saying disposed of an asset the ledger still carries.
 *
 * Amending follows the guarded-write pattern used across the application: lock
 * the row, do nothing if the values are unchanged, update guarded by
 * row_version so a caller holding a stale copy is refused, then read back and
 * stamp the change into the audit chain.
 */
final class SaveAsset
{
    public function __construct(
        private readonly CreateJournal $createJournal,
        private readonly PostJournal $postJournal,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  asset_category_id, code, name,
     *                                            acquisition_cost, useful_life_months,
     *                                            and optionally description,
     *                                            salvage_value, status,
     *                                            in_service_date and
     *                                            acquisition_account_id
     */
    public function __invoke(
        int $companyId,
        array $attributes,
        ?Asset $asset = null,
        ?int $expectedRowVersion = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Asset {
        return DB::transaction(function () use (
            $companyId,
            $attributes,
            $asset,
            $expectedRowVersion,
            $userId,
            $idempotencyKey
        ): Asset {
            return $asset === null
                ? $this->create($companyId, $attributes, $userId, $idempotencyKey)
                : $this->amend($companyId, $asset, $attributes, $expectedRowVersion, $userId, $idempotencyKey);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(
        int $companyId,
        array $attributes,
        ?int $userId,
        ?string $idempotencyKey,
    ): Asset {
        $category = $this->category($companyId, (int) ($attributes['asset_category_id'] ?? 0));

        // A retired category keeps the assets already written against it, but
        // may not be named by a new one: the status says what may be entered
        // from now on.
        if (! $category->status->acceptsNewAssets()) {
            throw AssetsException::categoryInactive($category);
        }

        $cost = Money::of($attributes['acquisition_cost'] ?? '0');
        $salvage = Money::of($attributes['salvage_value'] ?? '0');
        $life = (int) ($attributes['useful_life_months'] ?? $category->default_useful_life_months);

        $this->assertFigures($cost, $salvage, $life);

        $status = AssetStatus::from($attributes['status'] ?? AssetStatus::Draft->value);

        if ($status === AssetStatus::Disposed) {
            throw AssetStateException::disposalHasItsOwnRoute();
        }

        $inServiceDate = $attributes['in_service_date'] ?? null;

        $this->assertServiceDate($status, $inServiceDate);

        $asset = new Asset([
            'company_id' => $companyId,
            'asset_category_id' => $category->getKey(),
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => $status->value,
            'acquisition_cost' => $cost->toDecimal(),
            'salvage_value' => $salvage->toDecimal(),
            'useful_life_months' => $life,
            'in_service_date' => $inServiceDate,
            // Written rather than left to the column's default, so the row the
            // endpoint returns reads the same as the row the database holds.
            'accumulated_depreciation' => Money::zero()->toDecimal(),
        ]);

        $asset->save();

        if ($status === AssetStatus::InService) {
            $this->enterService($asset, $category, $attributes, $userId, $idempotencyKey);
        }

        $this->audit->record(
            $status === AssetStatus::InService ? 'asset.entered' : 'asset.created',
            $asset,
            null,
            $this->snapshot($asset),
        );

        return $asset;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function amend(
        int $companyId,
        Asset $asset,
        array $attributes,
        ?int $expectedRowVersion,
        ?int $userId,
        ?string $idempotencyKey,
    ): Asset {
        $locked = Asset::query()
            ->where('company_id', $companyId)
            ->whereKey($asset->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        // A disposed asset is history: its cost, its life and its status are
        // what they were when the disposal was posted, and only a reversal
        // changes them.
        if ($locked->isDisposed()) {
            throw AssetStateException::disposedIsImmutable($locked);
        }

        $categoryId = (int) ($attributes['asset_category_id'] ?? $locked->asset_category_id);
        $movingCategory = $categoryId !== (int) $locked->asset_category_id;

        if ($movingCategory && ! $locked->isDraft()) {
            throw AssetStateException::categoryLocked($locked);
        }

        $category = $this->category($companyId, $categoryId);

        if ($movingCategory && ! $category->status->acceptsNewAssets()) {
            throw AssetsException::categoryInactive($category);
        }

        $status = AssetStatus::from($attributes['status'] ?? $locked->status->value);

        if ($status === AssetStatus::Disposed) {
            throw AssetStateException::disposalHasItsOwnRoute();
        }

        if ($this->rank($status) < $this->rank($locked->status)) {
            throw AssetStateException::backwardStatusMove($locked, $status);
        }

        $posted = Money::of($locked->acquisition_cost);

        $cost = array_key_exists('acquisition_cost', $attributes)
            ? Money::of($attributes['acquisition_cost'])
            : $posted;

        // The cost reached the ledger when the asset entered service, so it may
        // only still be edited while the asset is a draft.
        if (! $cost->equals($posted) && ! $locked->isDraft()) {
            throw AssetsException::costIsImmutable();
        }

        $salvage = array_key_exists('salvage_value', $attributes)
            ? Money::of($attributes['salvage_value'])
            : Money::of($locked->salvage_value);

        $life = (int) ($attributes['useful_life_months'] ?? $locked->useful_life_months);

        $inServiceDate = array_key_exists('in_service_date', $attributes)
            ? $attributes['in_service_date']
            : $locked->in_service_date?->toDateString();

        $this->assertFigures($cost, $salvage, $life);
        $this->assertServiceDate($status, $inServiceDate);

        // Anything a run has already charged against is no longer the caller's
        // to restate. Until the first run, the life and the salvage value are
        // still the caller's to get right.
        if (Money::of($locked->accumulated_depreciation)->isPositive()
            && (! $salvage->equals(Money::of($locked->salvage_value))
                || $life !== (int) $locked->useful_life_months
                || $inServiceDate !== $locked->in_service_date?->toDateString())) {
            throw AssetStateException::financialsLocked($locked);
        }

        $before = $this->snapshot($locked);

        $after = [
            'asset_category_id' => $category->getKey(),
            'code' => $attributes['code'] ?? $locked->code,
            'name' => $attributes['name'] ?? $locked->name,
            'description' => array_key_exists('description', $attributes)
                ? $attributes['description']
                : $locked->description,
            'status' => $status->value,
            'acquisition_cost' => $cost->toDecimal(),
            'salvage_value' => $salvage->toDecimal(),
            'useful_life_months' => $life,
            'in_service_date' => $inServiceDate,
        ];

        // A save that changes nothing writes nothing and records nothing: an
        // audit chain full of entries that record no change buries the entries
        // that matter.
        $changed = array_filter(
            $after,
            static fn (mixed $value, string $key): bool => (string) $before[$key] !== (string) $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed === []) {
            return $locked;
        }

        $expected = $expectedRowVersion ?? $locked->row_version;

        $affected = Asset::query()
            ->whereKey($locked->getKey())
            ->where('row_version', $expected)
            ->update([
                ...$after,
                'row_version' => $expected + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw ConcurrentModificationException::row(
                sprintf('Asset %s', $locked->code),
                $expected,
            );
        }

        $updated = Asset::query()->findOrFail($locked->getKey());

        $enteringService = $locked->isDraft() && $status === AssetStatus::InService;

        if ($enteringService) {
            $this->enterService($updated, $category, $attributes, $userId, $idempotencyKey);
        }

        $this->audit->record(
            $enteringService ? 'asset.entered' : 'asset.updated',
            $updated,
            $before,
            $this->snapshot($updated),
        );

        return $updated;
    }

    /**
     * Places an asset in service by writing its cost into the ledger: Dr the
     * category's asset account, Cr whatever met the cost.
     *
     * The second side is named by the caller rather than guessed, because the
     * asset may have been bought for cash, paid out of a bank or owed to a
     * supplier, and only the caller knows which.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function enterService(
        Asset $asset,
        AssetCategory $category,
        array $attributes,
        ?int $userId,
        ?string $idempotencyKey,
    ): void {
        $account = $this->postableAccount(
            (int) $asset->company_id,
            $attributes['acquisition_account_id'] ?? null,
            'acquisition',
            AssetsException::acquisitionAccountRequired(),
        );

        $cost = Money::of($asset->acquisition_cost);
        $date = $asset->in_service_date->toDateString();
        $description = 'Acquisition: '.$asset->code.' '.$asset->name;

        $journal = ($this->createJournal)(new JournalEntryData(
            companyId: (int) $asset->company_id,
            postingDate: $date,
            journalType: JournalType::Acquisition,
            lines: [
                JournalLineData::debit((int) $category->asset_account_id, $cost, $description),
                JournalLineData::credit($account->getKey(), $cost, $description),
            ],
            reference: $asset->code,
            memo: $description,
            documentDate: $date,
            sourceType: Asset::class,
            sourceId: (int) $asset->getKey(),
            createdByUserId: $userId,
        ));

        ($this->postJournal)($journal, $idempotencyKey, $userId);
    }

    /**
     * Scoped to the caller's company in the same query that resolves it, so
     * naming another tenant's category is a 404 rather than an existence
     * oracle.
     */
    private function category(int $companyId, int $categoryId): AssetCategory
    {
        return AssetCategory::query()
            ->where('company_id', $companyId)
            ->whereKey($categoryId)
            ->firstOrFail();
    }

    /**
     * A named account, resolved inside the company and required to be one that
     * may carry a posting.
     */
    private function postableAccount(
        int $companyId,
        mixed $accountId,
        string $role,
        \RuntimeException $missing,
    ): Account {
        if ($accountId === null) {
            throw $missing;
        }

        $account = Account::query()
            ->where('company_id', $companyId)
            ->whereKey($accountId)
            ->first();

        if ($account === null) {
            throw AssetsException::unknownAccount($role);
        }

        if (! $account->is_postable) {
            throw AssetsException::wrongAccountType($role, $account, 'a postable account rather than a header');
        }

        return $account;
    }

    private function assertFigures(Money $cost, Money $salvage, int $life): void
    {
        if (! $cost->isPositive()) {
            throw AssetsException::nonPositiveCost();
        }

        if ($life < 1) {
            throw AssetsException::nonPositiveLife();
        }

        if ($salvage->isNegative()) {
            throw AssetsException::negativeSalvage();
        }

        if ($salvage->greaterThan($cost)) {
            throw AssetsException::salvageExceedsCost($cost, $salvage);
        }
    }

    /**
     * A draft has no date to count from and an asset in service has nothing
     * else: the date is what starts its life running.
     */
    private function assertServiceDate(AssetStatus $status, ?string $inServiceDate): void
    {
        if ($status === AssetStatus::InService && $inServiceDate === null) {
            throw AssetsException::inServiceDateRequired();
        }

        if ($status === AssetStatus::Draft && $inServiceDate !== null) {
            throw AssetsException::inServiceDateNotAllowed();
        }
    }

    private function rank(AssetStatus $status): int
    {
        return match ($status) {
            AssetStatus::Draft => 0,
            AssetStatus::InService => 1,
            AssetStatus::Disposed => 2,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Asset $asset): array
    {
        return [
            'asset_category_id' => (int) $asset->asset_category_id,
            'code' => $asset->code,
            'name' => $asset->name,
            'description' => $asset->description,
            'status' => $asset->status->value,
            'acquisition_cost' => (string) $asset->acquisition_cost,
            'salvage_value' => (string) $asset->salvage_value,
            'useful_life_months' => (int) $asset->useful_life_months,
            'in_service_date' => $asset->in_service_date?->toDateString(),
        ];
    }
}
