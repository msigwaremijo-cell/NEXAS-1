<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Assets\Enums\AssetStatus;
use App\Domain\Assets\Exceptions\AssetsException;
use App\Domain\Assets\Exceptions\AssetStateException;
use App\Domain\Assets\Services\DepreciationSchedule;
use App\Models\Account;
use App\Models\Asset;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Takes an asset out of the books at a date for a proceeds amount, and books
 * the difference between what it was worth in them and what it fetched.
 *
 * The disposal is one posting, because it is one event:
 *
 *   - the cost comes out of the category's asset account;
 *   - the accumulated depreciation goes back out of the contra account it was
 *     charged into;
 *   - what was received — if anything — is debited to the account the caller
 *     names, which is the account the money actually landed in;
 *   - whatever is left over is the gain or the loss, and it is booked to the
 *     category's disposal account. It is the balance of the entry rather than
 *     a figure worked out separately, so the disposal cannot be posted out of
 *     balance by an arithmetic slip.
 *
 * Depreciation is brought current first. An asset is depreciated up to the
 * date it leaves, so the figures the disposal takes out are the figures that
 * should be in the books at that date, not the ones as at the last run. The
 * charge for the period the disposal falls in goes through the same period run
 * as any other month, so a period is still depreciated once and by one
 * journal; the disposal then removes the asset, and the next run finds it gone
 * because it is no longer in service. Periods the company never ran before the
 * disposal are its own omission and are not backfilled here: the disposal
 * brings the asset current for the period it leaves in, and no further.
 *
 * The disposal is guarded like every other posting. It refuses an asset that is
 * not in service, an asset already disposed of, a disposal dated before the
 * asset went into service, negative proceeds, and a period or year the calendar
 * has closed. It refuses proceeds without an account, because money received is
 * a posting and a posting has two sides.
 *
 * A disposed asset is then history. It is not depreciated, not moved and not
 * disposed of twice; the only correction is a reversal, which is what
 * ReverseDisposal is for.
 */
final class DisposeAsset
{
    public function __construct(
        private readonly DepreciationSchedule $schedule,
        private readonly RunDepreciation $runDepreciation,
        private readonly CreateJournal $createJournal,
        private readonly PostJournal $postJournal,
        private readonly FiscalCalendar $calendar,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        int $companyId,
        Asset $asset,
        string $disposalDate,
        mixed $proceeds = '0',
        ?int $proceedsAccountId = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Journal {
        return DB::transaction(function () use (
            $companyId,
            $asset,
            $disposalDate,
            $proceeds,
            $proceedsAccountId,
            $userId,
            $idempotencyKey
        ): Journal {
            // Resolved inside the company in the same query that finds it, so
            // another tenant's asset cannot be disposed of against these books.
            $locked = Asset::query()
                ->where('company_id', $companyId)
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDisposable($locked, $disposalDate);

            // The period the disposal falls in, locked and required to accept
            // postings: a disposal is a posting like any other and cannot be
            // made into a period that has been closed or a year that has.
            $period = $this->calendar->lockOpen(
                $this->calendar->resolve($companyId, $disposalDate)
            );

            $this->depreciateUpTo($companyId, $locked, $period, $userId);

            $proceedsAmount = Money::of($proceeds);

            if ($proceedsAmount->isNegative()) {
                throw AssetsException::negativeProceeds();
            }

            // Money received is a posting: it has to say where it went. A
            // disposal that receives nothing — a scrapped asset — names no
            // account, because there is no second side to give it.
            $proceedsAccount = $proceedsAmount->isPositive()
                ? $this->postableAccount(
                    $companyId,
                    $proceedsAccountId,
                    'disposal proceeds',
                    AssetsException::proceedsAccountRequired(),
                )
                : null;

            // The period run, if it happened, moved the asset's accumulated
            // figure and its row_version, so what the disposal takes out is
            // read again rather than taken from the copy read before the run.
            $current = Asset::query()
                ->where('company_id', $companyId)
                ->whereKey($locked->getKey())
                ->with('category')
                ->firstOrFail();

            $reference = $current->code;
            $memo = 'Disposal: '.$current->code.' '.$current->name;

            $journal = ($this->createJournal)(new JournalEntryData(
                companyId: $companyId,
                postingDate: $disposalDate,
                journalType: JournalType::Disposal,
                lines: $this->lines($current, $proceedsAmount, $proceedsAccount),
                reference: $reference,
                memo: $memo,
                documentDate: $disposalDate,
                sourceType: Asset::class,
                sourceId: (int) $current->getKey(),
                createdByUserId: $userId,
            ));

            $posted = ($this->postJournal)($journal, $idempotencyKey, $userId);

            $this->recordDisposal($current, $disposalDate, $proceedsAmount, $posted);

            $difference = $proceedsAmount->minus($current->netBookValue());

            $this->audit->record('asset.disposed', $current, [
                'status' => AssetStatus::InService->value,
            ], [
                'status' => AssetStatus::Disposed->value,
                'disposed_on' => $disposalDate,
                'proceeds' => $proceedsAmount->toDecimal(),
                'gain_or_loss' => $difference->toDecimal(),
                'journal_no' => $posted->journal_no,
            ]);

            return $posted;
        });
    }

    /**
     * Only an asset in service can be disposed of, only once, and never before
     * it went into service.
     */
    private function assertDisposable(Asset $asset, string $disposalDate): void
    {
        if ($asset->isDisposed()) {
            throw AssetStateException::alreadyDisposed($asset);
        }

        if (! $asset->status->isDisposable()) {
            throw AssetStateException::notInService($asset);
        }

        $inServiceDate = $asset->in_service_date?->toDateString();

        if ($inServiceDate === null || $disposalDate < $inServiceDate) {
            throw AssetsException::disposalBeforeInService(
                $disposalDate,
                $inServiceDate ?? 'a date that has not been set',
            );
        }
    }

    /**
     * Charges the period the disposal falls in, if the asset has not already
     * been charged through it and has something left to give.
     *
     * The run is the company's period run rather than a charge aimed at this
     * one asset, because a period's charge is a period's figure and one journal
     * is how this application posts one. It is a no-op where the period has
     * already been run — the asset's own depreciated_through says so — and where
     * the asset is exhausted, so a disposal does not manufacture a journal of
     * nothing.
     *
     * The caller's idempotency key is deliberately not passed down: it belongs
     * to the disposal, and a second claim under it would be a different request
     * wearing the same name.
     */
    private function depreciateUpTo(
        int $companyId,
        Asset $asset,
        FiscalPeriod $period,
        ?int $userId,
    ): void {
        $through = $asset->depreciated_through;

        if ($through !== null && $through->greaterThanOrEqualTo($period->ends_on)) {
            return;
        }

        if (! $this->schedule->charge($asset, $period)->isPositive()) {
            return;
        }

        ($this->runDepreciation)($companyId, $period, $userId);
    }

    /**
     * The disposal entry: cost out, accumulated depreciation out, proceeds in,
     * and the difference to the disposal account.
     *
     * The gain or the loss is what balances the entry rather than a figure
     * computed on its own, so the two cannot disagree.
     *
     * @return array<int, JournalLineData>
     */
    private function lines(Asset $asset, Money $proceeds, ?Account $proceedsAccount): array
    {
        $category = $asset->category;
        $cost = Money::of($asset->acquisition_cost);
        $accumulated = Money::of($asset->accumulated_depreciation);
        $netBookValue = $cost->minus($accumulated);
        $description = $asset->code.' '.$asset->name;

        $lines = [];

        // What was charged into the contra account over the asset's life comes
        // back out of it. A line of nothing is left out: a journal line carries
        // an amount, not a placeholder.
        if ($accumulated->isPositive()) {
            $lines[] = JournalLineData::debit(
                (int) $category->accumulated_depreciation_account_id,
                $accumulated,
                $description,
            );
        }

        $lines[] = JournalLineData::credit(
            (int) $category->asset_account_id,
            $cost,
            $description,
        );

        if ($proceedsAccount !== null && $proceeds->isPositive()) {
            $lines[] = JournalLineData::debit(
                $proceedsAccount->getKey(),
                $proceeds,
                $description,
            );
        }

        $difference = $proceeds->minus($netBookValue);

        if ($difference->isPositive()) {
            $lines[] = JournalLineData::credit(
                (int) $category->disposal_account_id,
                $difference,
                $description,
            );
        } elseif ($difference->isNegative()) {
            $lines[] = JournalLineData::debit(
                (int) $category->disposal_account_id,
                $difference->absolute(),
                $description,
            );
        }

        return $lines;
    }

    /**
     * Moves the register row to disposed under a row_version guard, so an asset
     * amended underneath the disposal is refused rather than silently
     * overwritten.
     *
     * What the asset cost and what it had been depreciated by are left on the
     * row: they are what it was, and the register's answer to a disposed asset
     * is history, not an empty row.
     */
    private function recordDisposal(
        Asset $asset,
        string $disposalDate,
        Money $proceeds,
        Journal $journal,
    ): void {
        $expected = $asset->row_version;

        $affected = Asset::query()
            ->whereKey($asset->getKey())
            ->where('row_version', $expected)
            ->update([
                'status' => AssetStatus::Disposed->value,
                'disposed_on' => $disposalDate,
                'disposal_proceeds' => $proceeds->toDecimal(),
                'disposal_journal_id' => $journal->getKey(),
                'row_version' => $expected + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw ConcurrentModificationException::row(
                sprintf('Asset %s', $asset->code),
                $expected,
            );
        }
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
}
