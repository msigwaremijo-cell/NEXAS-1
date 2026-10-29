<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Inventory\Enums\InventoryCosting;
use App\Domain\Inventory\Services\StockPolicy;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * How the company keeps its stock books.
 *
 * This is the settings screen the costing lock was waiting for. The guard in
 * StockPolicy refuses a change of costing method once any movement exists, but
 * a guard is only worth having if the thing that writes the setting calls it,
 * so the write lives here and nowhere else: `inventory_costing` is not a column
 * anyone may edit around, it is a policy set through this route, which asks the
 * policy first.
 *
 * The settings live inside the `companies.settings` blob rather than in columns
 * of their own, and the blob is merged rather than replaced, so a screen that
 * changes one setting does not silently drop the others it never mentioned.
 *
 * Both settings are read back on the way out, and read back the same way the
 * engine reads them, so what the screen is shown and what a receipt will do
 * cannot come from two different places.
 *
 * The two purchase accounts are part of the same screen because they are the
 * same kind of choice. Goods received not invoiced is the liability a receipt
 * credits and a bill clears, and the variance account is where a difference
 * between the two lands. A stock account is not a preference, so changing
 * either one is written to the audit chain.
 */
class CompanyController extends Controller
{
    public function __construct(
        private readonly StockPolicy $policy,
        private readonly AuditTrail $audit,
    ) {}

    public function inventorySettings(Request $request): JsonResponse
    {
        $this->authorize('viewSettings', Company::class);

        return response()->json(['data' => $this->present($this->company($request))]);
    }

    public function updateInventorySettings(Request $request): JsonResponse
    {
        $this->authorize('updateSettings', Company::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'inventory_costing' => ['sometimes', Rule::enum(InventoryCosting::class)],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            // Goods received not invoiced is what the company owes for goods it
            // has but has no invoice for yet, so it is a liability or it is not
            // the account it says it is.
            'grni_account_id' => $this->accountRule($companyId, AccountType::Liability),
            'purchase_variance_account_id' => $this->accountRule($companyId),
        ]);

        // Locked, because the guard reads the stock movements table and the
        // write follows it: without the lock two settings writes could both
        // read the shelves as empty and both be allowed through, leaving the
        // last one to win and the first one's method silently overruled.
        $company = DB::transaction(function () use ($data, $companyId): Company {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();

            $settings = $company->settings ?? [];
            $changed = [];

            if (array_key_exists('inventory_costing', $data)) {
                $this->policy->assertCostingMayChangeTo($company, $data['inventory_costing']);

                $settings['inventory_costing'] = $data['inventory_costing'];
            }

            if (array_key_exists('allow_negative_stock', $data)) {
                $settings['allow_negative_stock'] = (bool) $data['allow_negative_stock'];
            }

            foreach (['grni_account_id', 'purchase_variance_account_id'] as $key) {
                if (! array_key_exists($key, $data)) {
                    continue;
                }

                $current = isset($settings[$key]) ? (int) $settings[$key] : null;
                $requested = $data[$key] === null ? null : (int) $data[$key];

                if ($current === $requested) {
                    continue;
                }

                $changed[$key] = ['from' => $current, 'to' => $requested];
                $settings[$key] = $requested;
            }

            $company->settings = $settings;
            $company->save();

            if ($changed !== []) {
                // Written to the chain rather than left in the settings blob:
                // which account a receipt credits and which one a price
                // difference lands on is an accounting decision, and a
                // decision that can be changed without a trace is one that
                // cannot be audited.
                $this->audit->record(
                    'company.inventory_settings.updated',
                    $company,
                    array_map(static fn (array $change): ?int => $change['from'], $changed),
                    array_map(static fn (array $change): ?int => $change['to'], $changed),
                    (int) $company->getKey(),
                );
            }

            return $company;
        });

        return response()->json(['data' => $this->present($company)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        $costing = $this->policy->costing($company);

        return [
            'company_id' => (int) $company->getKey(),
            'inventory_costing' => $costing->value,
            // Reported rather than hidden, because setting a method the system
            // cannot value stock by is allowed and will stop stock from moving
            // at all. A screen that does not say so leaves the caller to find
            // out from a refused receipt.
            'costing_implemented' => $costing->isImplemented(),
            // The read side of the lock: once this is true the method above is
            // settled and will not be changed by any later request.
            'costing_locked' => $this->policy->costingIsLocked($company),
            'allow_negative_stock' => $this->policy->allowsNegativeStock($company),
            'grni_account_id' => $this->policy->grniAccountId($company),
            'purchase_variance_account_id' => $this->policy->purchaseVarianceAccountId($company),
        ];
    }

    /**
     * An account the caller may name, if it is one of theirs and is still in
     * use. A second rule pins the type where the setting only makes sense as
     * one kind of account.
     *
     * @return array<int, mixed>
     */
    private function accountRule(int $companyId, ?AccountType $requiredType = null): array
    {
        $rules = [
            'sometimes',
            'nullable',
            'integer',
            'min:1',
            Rule::exists('accounts', 'id')
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->whereNull('deleted_at'),
        ];

        if ($requiredType !== null) {
            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($companyId, $requiredType): void {
                $account = Account::query()
                    ->where('company_id', $companyId)
                    ->whereKey($value)
                    ->first();

                if ($account === null || $account->account_type !== $requiredType) {
                    $fail(sprintf(
                        'The %s must be a %s account.',
                        str_replace('_', ' ', $attribute),
                        strtolower($requiredType->label())
                    ));
                }
            };
        }

        return $rules;
    }

    private function company(Request $request): Company
    {
        return Company::query()->findOrFail($this->companyId($request));
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
