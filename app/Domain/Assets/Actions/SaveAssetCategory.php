<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\NormalBalance;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Assets\Enums\AssetCategoryStatus;
use App\Domain\Assets\Exceptions\AssetsException;
use App\Models\Account;
use App\Models\AssetCategory;
use Illuminate\Support\Facades\DB;

/**
 * Creates or amends a fixed-asset category, the four accounts its assets post
 * to, and nothing else.
 *
 * The four accounts are the whole reason this is guarded rather than a plain
 * save. A category decides where a whole class of depreciation charges lands,
 * so a mis-set account silently misstates every later period. Each named
 * account is therefore resolved inside the caller's own company (another
 * tenant's account is refused rather than borrowed) and checked for the type
 * the role needs:
 *
 *   - the asset account must be an asset account with a debit balance, which
 *     is where the cost is carried;
 *   - the accumulated depreciation account is the contra-asset: an asset
 *     account with a *credit* balance, because the chart of accounts has no
 *     separate contra-asset type and the credit balance is what makes it
 *     contra;
 *   - the depreciation expense and the disposal account must both be expense
 *     accounts — a gain on disposal is a credit to the disposal account and a
 *     loss a debit, so an expense account holds either.
 *
 * On an amendment the write follows the guarded-write pattern used across the
 * application: lock the row, do nothing if the values are unchanged, update
 * guarded by row_version so a caller holding a stale copy is refused, then
 * read the row back and stamp the change into the audit chain. Every change
 * therefore has one audit entry and an unchanged save has none.
 */
final class SaveAssetCategory
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  code, name, the four account
     *                                            ids, default_useful_life_months
     *                                            and optionally description and
     *                                            status
     */
    public function __invoke(
        int $companyId,
        array $attributes,
        ?AssetCategory $category = null,
        ?int $expectedRowVersion = null,
    ): AssetCategory {
        return DB::transaction(function () use ($companyId, $attributes, $category, $expectedRowVersion): AssetCategory {
            if ($category === null) {
                $accounts = $this->resolveAccounts($companyId, $attributes);

                $created = new AssetCategory([
                    'company_id' => $companyId,
                    'code' => $attributes['code'],
                    'name' => $attributes['name'],
                    'description' => $attributes['description'] ?? null,
                    'asset_account_id' => $accounts['asset']->getKey(),
                    'accumulated_depreciation_account_id' => $accounts['accumulated']->getKey(),
                    'depreciation_expense_account_id' => $accounts['expense']->getKey(),
                    'disposal_account_id' => $accounts['disposal']->getKey(),
                    'default_useful_life_months' => (int) $attributes['default_useful_life_months'],
                    'status' => $attributes['status'] ?? AssetCategoryStatus::Active->value,
                ]);

                $created->save();

                $this->audit->record('asset_category.created', $created, null, $this->snapshot($created));

                return $created;
            }

            $locked = AssetCategory::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // An amendment names only what it changes, so an account left out
            // keeps the one the category already posts to rather than being
            // read as "no account".
            $accounts = $this->resolveAccounts($companyId, [
                'asset_account_id' => $attributes['asset_account_id'] ?? $locked->asset_account_id,
                'accumulated_depreciation_account_id' => $attributes['accumulated_depreciation_account_id']
                    ?? $locked->accumulated_depreciation_account_id,
                'depreciation_expense_account_id' => $attributes['depreciation_expense_account_id']
                    ?? $locked->depreciation_expense_account_id,
                'disposal_account_id' => $attributes['disposal_account_id'] ?? $locked->disposal_account_id,
            ]);

            $before = $this->snapshot($locked);

            $after = [
                'code' => $attributes['code'] ?? $locked->code,
                'name' => $attributes['name'] ?? $locked->name,
                'description' => array_key_exists('description', $attributes)
                    ? $attributes['description']
                    : $locked->description,
                'asset_account_id' => $accounts['asset']->getKey(),
                'accumulated_depreciation_account_id' => $accounts['accumulated']->getKey(),
                'depreciation_expense_account_id' => $accounts['expense']->getKey(),
                'disposal_account_id' => $accounts['disposal']->getKey(),
                'default_useful_life_months' => (int) ($attributes['default_useful_life_months']
                    ?? $locked->default_useful_life_months),
                'status' => $attributes['status'] ?? $locked->status->value,
            ];

            // A save that changes nothing writes nothing and records nothing:
            // an audit chain full of entries that record no change buries the
            // entries that matter.
            $changed = array_filter(
                $after,
                static fn (mixed $value, string $key): bool => (string) $before[$key] !== (string) $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed === []) {
                return $locked;
            }

            $expected = $expectedRowVersion ?? $locked->row_version;

            $affected = AssetCategory::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $expected)
                ->update([
                    ...$after,
                    'row_version' => $expected + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Asset category %s', $locked->code),
                    $expected,
                );
            }

            $updated = AssetCategory::query()->findOrFail($locked->getKey());

            $this->audit->record('asset_category.updated', $updated, $before, $this->snapshot($updated));

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{asset: Account, accumulated: Account, expense: Account, disposal: Account}
     */
    private function resolveAccounts(int $companyId, array $attributes): array
    {
        $asset = $this->account($companyId, $attributes['asset_account_id'] ?? 0, 'asset');

        if ($asset->account_type !== AccountType::Asset || $asset->normal_balance !== NormalBalance::Debit) {
            throw AssetsException::wrongAccountType(
                'asset',
                $asset,
                'an asset account with a debit balance',
            );
        }

        $accumulated = $this->account(
            $companyId,
            $attributes['accumulated_depreciation_account_id'] ?? 0,
            'accumulated depreciation',
        );

        if ($accumulated->account_type !== AccountType::Asset || $accumulated->normal_balance !== NormalBalance::Credit) {
            throw AssetsException::wrongAccountType(
                'accumulated depreciation',
                $accumulated,
                'an asset account with a credit balance, which is what makes it a contra-asset',
            );
        }

        $expense = $this->account(
            $companyId,
            $attributes['depreciation_expense_account_id'] ?? 0,
            'depreciation expense',
        );

        if ($expense->account_type !== AccountType::Expense) {
            throw AssetsException::wrongAccountType('depreciation expense', $expense, 'an expense account');
        }

        $disposal = $this->account($companyId, $attributes['disposal_account_id'] ?? 0, 'disposal');

        if ($disposal->account_type !== AccountType::Expense) {
            throw AssetsException::wrongAccountType(
                'disposal',
                $disposal,
                'an expense account, which is where a gain or a loss on disposal is booked',
            );
        }

        return ['asset' => $asset, 'accumulated' => $accumulated, 'expense' => $expense, 'disposal' => $disposal];
    }

    private function account(int $companyId, int $id, string $role): Account
    {
        $account = Account::query()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->first();

        if ($account === null) {
            throw AssetsException::unknownAccount($role);
        }

        if (! $account->is_postable) {
            throw AssetsException::wrongAccountType($role, $account, 'a postable account rather than a header');
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AssetCategory $category): array
    {
        return [
            'code' => $category->code,
            'name' => $category->name,
            'description' => $category->description,
            'asset_account_id' => (int) $category->asset_account_id,
            'accumulated_depreciation_account_id' => (int) $category->accumulated_depreciation_account_id,
            'depreciation_expense_account_id' => (int) $category->depreciation_expense_account_id,
            'disposal_account_id' => (int) $category->disposal_account_id,
            'default_useful_life_months' => (int) $category->default_useful_life_months,
            'status' => $category->status->value,
        ];
    }
}
