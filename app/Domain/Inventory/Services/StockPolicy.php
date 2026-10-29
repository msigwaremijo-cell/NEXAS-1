<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryCosting;
use App\Domain\Inventory\Exceptions\StockStateException;
use App\Domain\Inventory\Exceptions\UnsupportedCostingMethodException;
use App\Models\Company;
use App\Models\StockMovement;

/**
 * What a company has decided about its stock, and what that decision forbids.
 *
 * Both settings live on the company row rather than in a column of their own,
 * because they are choices about how the books are kept rather than facts
 * about what happened. A company that has not chosen falls back to the
 * application default, so a tenant created before anybody opened the settings
 * screen still has an answer.
 *
 * Nothing here writes a journal. This class answers questions — how is stock
 * costed, may it go negative — and refuses the two things that cannot be
 * answered at all: a costing method that has not been built, and a change of
 * method once stock has started moving.
 */
final class StockPolicy
{
    /**
     * The costing method in force for a company.
     *
     * A value nobody recognises is reported as weighted average, which is the
     * one method that is not implemented. That is deliberate: a typo in a
     * settings row should stop stock from moving with a sentence, rather than
     * quietly costing every issue the one way the system does know.
     */
    public function costing(Company $company): InventoryCosting
    {
        $configured = ($company->settings ?? [])['inventory_costing'] ?? null;

        if (! is_string($configured)) {
            $configured = (string) config('nexas.policy.inventory_costing', '');
        }

        return InventoryCosting::tryFrom($configured) ?? InventoryCosting::WeightedAverage;
    }

    /**
     * The costing method, once it is known that the system implements it.
     *
     * Every action that touches a layer calls this first, so a company asking
     * for a method that has not been built finds out before it has written
     * anything at all.
     */
    public function assertCostingImplemented(Company $company): InventoryCosting
    {
        $method = $this->costing($company);

        if (! $method->isImplemented()) {
            throw UnsupportedCostingMethodException::notImplemented($method);
        }

        return $method;
    }

    public function allowsNegativeStock(Company $company): bool
    {
        $configured = ($company->settings ?? [])['allow_negative_stock']
            ?? config('nexas.policy.allow_negative_stock', false);

        return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The liability account a purchase receipt credits and a bill clears.
     *
     * Goods received not invoiced: the company has the stock but not yet the
     * invoice for it. A purchase raises this liability when the goods arrive
     * and clears it when the bill for them is posted, so the two events are
     * told apart instead of the bill receiving the stock a second time.
     */
    public function grniAccountId(Company $company): ?int
    {
        return $this->accountSetting($company, 'grni_account_id');
    }

    /**
     * Where a difference between what a receipt was booked at and what the
     * bill for it says lands.
     */
    public function purchaseVarianceAccountId(Company $company): ?int
    {
        return $this->accountSetting($company, 'purchase_variance_account_id');
    }

    /**
     * The pair of accounts a purchase needs, once it is known there is one.
     *
     * Both are refused together rather than one at a time: a receipt with no
     * GRNI account has nowhere to credit, and a bill whose price differs from
     * the receipt with no variance account has nowhere to put the difference.
     * Asking for them as a pair is what stops the first posting of a purchase
     * from being the moment the company finds out the second one is missing.
     *
     * @return array{grni: int, variance: int}
     */
    public function purchaseAccounts(Company $company): array
    {
        $grni = $this->grniAccountId($company);
        $variance = $this->purchaseVarianceAccountId($company);

        if ($grni === null) {
            throw StockStateException::grniAccountNotConfigured();
        }

        if ($variance === null) {
            throw StockStateException::purchaseVarianceAccountNotConfigured();
        }

        return ['grni' => $grni, 'variance' => $variance];
    }

    private function accountSetting(Company $company, string $key): ?int
    {
        $configured = ($company->settings ?? [])[$key] ?? null;

        return is_numeric($configured) ? (int) $configured : null;
    }

    /**
     * Refuse a change of costing method once stock has started moving.
     *
     * This is the guard the company settings screen has to call before it
     * writes a new method. Once a movement exists the layers it created were
     * priced by the method that was in force, so switching now would value
     * what is left by a rule the stock was never bought under, and the
     * difference would land nowhere in particular.
     *
     * Any movement counts, reversed ones included: a movement that has been
     * reversed still happened, and the layers it touched were priced by the
     * method in force at the time.
     */
    public function assertCostingMayChangeTo(Company $company, string $requested): void
    {
        $current = $this->costing($company);

        if ($current->value === $requested) {
            return;
        }

        if ($this->hasMovements((int) $company->getKey())) {
            throw StockStateException::costingMethodLocked($current->value, $requested);
        }
    }

    /**
     * Whether the costing method is already spoken for.
     *
     * The read side of the guard above, so the settings screen can say the
     * method is settled rather than offering a field that will be refused the
     * moment it is used. It answers the same question the guard asks and by
     * the same means, so the two cannot drift apart.
     */
    public function costingIsLocked(Company $company): bool
    {
        return $this->hasMovements((int) $company->getKey());
    }

    private function hasMovements(int $companyId): bool
    {
        return StockMovement::query()->where('company_id', $companyId)->exists();
    }
}
