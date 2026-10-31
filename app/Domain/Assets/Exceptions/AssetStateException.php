<?php

namespace App\Domain\Assets\Exceptions;

use App\Domain\Assets\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\FiscalPeriod;

/**
 * A fixed-asset request that collides with the state the books are in.
 *
 * These are not things the caller got wrong: a period that has already been
 * depreciated, an asset that has already been disposed of, a status that only
 * moves one way. Retrying the same request unchanged produces the same answer,
 * so they surface as a 409 through the AssetStateException mapping rather than
 * as a 422, and they are matched before the AssetsException base class because
 * each of these is also a fixed-asset refusal.
 */
final class AssetStateException extends AssetsException
{
    /**
     * A draft asset has not been placed in service, and a disposed asset has
     * stopped: neither has a month to depreciate.
     */
    public static function notDepreciable(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) is %s, so it is not depreciated. Only an asset in service carries a '
            .'depreciation charge.',
            $asset->code,
            $asset->name,
            $asset->status?->value ?? 'unknown'
        ));
    }

    public static function alreadyDisposed(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) was disposed of on %s. An asset is disposed of once; the correction '
            .'is to reverse the disposal journal.',
            $asset->code,
            $asset->name,
            $asset->disposed_on?->toDateString() ?? 'an earlier date'
        ));
    }

    /**
     * A disposed asset is history. Its cost, its life and its status are what
     * they were when the disposal was posted, and only a reversal changes them.
     */
    public static function disposedIsImmutable(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) has been disposed of, so it can no longer be amended. Reverse the '
            .'disposal journal to bring it back first.',
            $asset->code,
            $asset->name
        ));
    }

    public static function backwardStatusMove(Asset $asset, AssetStatus $to): self
    {
        return new self(sprintf(
            'Asset %s is %s and cannot move back to %s. Status moves draft -> in service -> '
            .'disposed, and a move is undone by reversing the journal it wrote.',
            $asset->code,
            $asset->status?->value ?? 'unknown',
            $to->value
        ));
    }

    /**
     * A running asset's life and salvage value have already been depreciated
     * against, so changing them would restate charges already posted.
     */
    public static function financialsLocked(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) is %s, so its useful life and salvage value can no longer be changed. '
            .'They have been depreciated against; correcting them means reversing the journals '
            .'they were charged through.',
            $asset->code,
            $asset->name,
            $asset->status?->value ?? 'unknown'
        ));
    }

    /**
     * Only an asset in service can be disposed of. A draft has never reached
     * the books, so there is no cost or accumulated depreciation in them to
     * take out.
     */
    public static function notInService(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) is %s, so it cannot be disposed of. Only an asset in service has '
            .'cost and accumulated depreciation in the books to take out.',
            $asset->code,
            $asset->name,
            $asset->status?->value ?? 'unknown'
        ));
    }

    /**
     * The category is what decided which accounts the cost was posted to, so
     * moving the asset to another category after its cost has reached the
     * ledger would leave that cost sitting in an account the register no longer
     * names.
     */
    public static function categoryLocked(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) has been entered, and its cost is posted to its category\'s accounts. '
            .'Moving it to another category now would leave the cost posted to an account the '
            .'register no longer names.',
            $asset->code,
            $asset->name
        ));
    }

    /**
     * There is no disposal to undo.
     */
    public static function notDisposed(Asset $asset): self
    {
        return new self(sprintf(
            'Asset %s (%s) is %s, so there is no disposal to reverse.',
            $asset->code,
            $asset->name,
            $asset->status?->value ?? 'unknown'
        ));
    }

    /**
     * The mirror of periodAlreadyDepreciated: an ask to undo a run that was
     * never made.
     */
    public static function periodNotDepreciated(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'There is no depreciation posted for %s to reverse.',
            $period->name
        ));
    }

    /**
     * A disposal is entered by its own route, not by moving an asset's status:
     * the move is what writes the journal, and a status change would leave the
     * asset disposed of with nothing in the books to say so.
     */
    public static function disposalHasItsOwnRoute(): self
    {
        return new self(
            'An asset is disposed of by the disposal route, not by setting its status: the '
            .'disposal is what removes the cost and the accumulated depreciation and writes the '
            .'entry that does it.'
        );
    }

    /**
     * One run per period. The journal for this period already exists, so a
     * second run would depreciate every asset twice.
     */
    public static function periodAlreadyDepreciated(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'Depreciation for %s has already been run. A period is depreciated once; the '
            .'correction is to reverse the journal the run wrote.',
            $period->name
        ));
    }

    /**
     * Nothing left to charge. A run that would write a journal of nothing is
     * refused rather than posting a row that says no more than the absence of
     * one already says.
     */
    public static function nothingToDepreciate(FiscalPeriod $period): self
    {
        return new self(sprintf(
            'There is nothing to depreciate for %s: no asset in service is still inside its '
            .'life in that period.',
            $period->name
        ));
    }
}
