<?php

namespace App\Domain\Assets\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Support\Money;

/**
 * A fixed-asset request the caller can fix and resubmit.
 *
 * Everything here is about the shape of what was asked — a salvage value above
 * the cost, a life of zero months, an asset placed in service with no date to
 * count from — rather than about the state of the books. It therefore surfaces
 * as a 422 through the AssetsException mapping, and a corrected request is
 * expected to succeed.
 */
class AssetsException extends AccountingException
{
    public static function nonPositiveCost(): self
    {
        return new self('An asset has to cost something. The acquisition cost must be greater than zero.');
    }

    public static function nonPositiveLife(): self
    {
        return new self('A useful life is stated in whole months and has to be at least one of them.');
    }

    public static function salvageExceedsCost(Money $cost, Money $salvage): self
    {
        return new self(sprintf(
            'The salvage value (%s) cannot be more than the acquisition cost (%s): an asset '
            .'cannot be expected to end its life worth more than it was bought for.',
            $salvage->toDecimal(),
            $cost->toDecimal()
        ));
    }

    /**
     * A negative salvage value would let a run depreciate below nothing, which
     * is not what a scrap value means.
     */
    public static function negativeSalvage(): self
    {
        return new self('A salvage value cannot be negative. An asset is not expected to end its life owing money.');
    }

    public static function inServiceDateRequired(): self
    {
        return new self(
            'An asset placed in service needs the date it went into service on, because that '
            .'is the month its depreciation is counted from.'
        );
    }

    public static function inServiceDateNotAllowed(): self
    {
        return new self(
            'A draft asset cannot carry an in-service date: it has not been placed in service '
            .'yet, and giving it a date would start its life running.'
        );
    }

    public static function categoryInactive(AssetCategory $category): self
    {
        return new self(sprintf(
            'Category %s (%s) is no longer active, so no new asset may be entered against it. '
            .'Move it back to active first if that is what you meant.',
            $category->code,
            $category->name
        ));
    }

    public static function disposalBeforeInService(string $disposalDate, string $inServiceDate): self
    {
        return new self(sprintf(
            'An asset cannot be disposed of on %s when it only went into service on %s.',
            $disposalDate,
            $inServiceDate
        ));
    }

    public static function proceedsAccountRequired(): self
    {
        return new self(
            'A disposal that receives money has to name the account the proceeds were received '
            .'into, so the entry says where the money went rather than only that it came.'
        );
    }

    /**
     * A negative proceeds figure would mean paying to be rid of the asset while
     * booking a gain, which is not what a sale means. Costs of disposal belong
     * in the disposal account, not in the proceeds.
     */
    public static function negativeProceeds(): self
    {
        return new self(
            'Disposal proceeds cannot be negative. Record the costs of getting rid of the asset '
            .'in the disposal account rather than as negative proceeds.'
        );
    }

    /**
     * Entering an asset writes its cost into the ledger, so the cost has to
     * have a second side like every other posting.
     */
    public static function acquisitionAccountRequired(): self
    {
        return new self(
            'Entering an asset has to name the account its cost was met from — a bank, a cash '
            .'box, a supplier — because the cost is a real posting and a posting has two sides.'
        );
    }

    /**
     * An account named on a category, or on an acquisition, that is not one of
     * the company's own. Resolving it inside the company is what stops a
     * category from pointing at another tenant's books.
     */
    public static function unknownAccount(string $role): self
    {
        return new self(sprintf(
            'The %s account named is not one of this company\'s accounts.',
            $role
        ));
    }

    public static function wrongAccountType(string $role, Account $account, string $expected): self
    {
        return new self(sprintf(
            'The %s account must be %s; %s (%s) is not.',
            $role,
            $expected,
            $account->code,
            $account->name
        ));
    }

    /**
     * The cost is posted when the asset is entered, so editing it afterwards
     * would leave the register disagreeing with the ledger.
     */
    public static function costIsImmutable(): self
    {
        return new self(
            'An asset\'s acquisition cost is written to the ledger when the asset is entered, so '
            .'it cannot be edited afterwards. Correct the entry by reversing the journal it wrote.'
        );
    }
}
