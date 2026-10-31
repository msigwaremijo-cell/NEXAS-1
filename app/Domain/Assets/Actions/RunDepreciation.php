<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Assets\Enums\AssetStatus;
use App\Domain\Assets\Exceptions\AssetStateException;
use App\Domain\Assets\Services\DepreciationSchedule;
use App\Models\Asset;
use App\Models\AssetDepreciationLine;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Charges one period's depreciation on every asset in service, as one journal.
 *
 * One journal per period, not one per asset, because the charge is a period's
 * figure and the assets are how it is arrived at: the entry debits the expense
 * and credits the accumulated depreciation with one pair of lines per asset, so
 * a reader can see which assets the charge was made of without the ledger
 * needing an asset column.
 *
 * A second run for the same period is refused rather than allowed to overlap.
 * The test is the period's own depreciation journal: if one exists and has not
 * been reversed, the period has been run. That is what makes the run idempotent
 * at the level that matters — the same request twice writes one journal, not
 * two — and it is backed underneath by UNIQUE(asset_id, fiscal_period_id) on
 * the run's own lines, which no PHP path can get around.
 *
 * The run is guarded like any other posting. It refuses a period the calendar
 * has closed or locked, and its period is locked for the length of the
 * transaction so a close cannot interleave with it. It writes nothing when
 * every asset answers zero — an empty journal records no more than the absence
 * of one already does. Undoing it is a reversal of the journal it wrote, not a
 * deletion: the run's own lines are given back and the assets' accumulated
 * depreciation is put back with them.
 *
 * Never below salvage, never a draft, never a disposed asset: the schedule
 * answers those, and this only decides what a period with nothing to charge
 * means.
 */
final class RunDepreciation
{
    public function __construct(
        private readonly DepreciationSchedule $schedule,
        private readonly CreateJournal $createJournal,
        private readonly PostJournal $postJournal,
        private readonly FiscalCalendar $calendar,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        int $companyId,
        FiscalPeriod $period,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Journal {
        return DB::transaction(function () use ($companyId, $period, $userId, $idempotencyKey): Journal {
            // Resolved inside the company in the same query that finds it, so
            // another tenant's period cannot be run against these books.
            $scoped = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->whereKey($period->getKey())
                ->firstOrFail();

            // Takes the row lock and refuses a period or a year that no longer
            // accepts postings, which is what stops a close interleaving with
            // the run.
            $open = $this->calendar->lockOpen($scoped);

            $this->assertNotAlreadyRun($companyId, $open);

            $charges = $this->charges($companyId, $open);

            if ($charges === []) {
                throw AssetStateException::nothingToDepreciate($open);
            }

            $postingDate = $open->ends_on->toDateString();

            $journal = ($this->createJournal)(new JournalEntryData(
                companyId: $companyId,
                postingDate: $postingDate,
                journalType: JournalType::Depreciation,
                lines: $this->lines($charges),
                reference: $open->name,
                memo: 'Depreciation for '.$open->name,
                documentDate: $postingDate,
                createdByUserId: $userId,
            ));

            $posted = ($this->postJournal)($journal, $idempotencyKey, $userId);

            $total = $this->recordCharges($companyId, $open, $posted, $charges);

            $this->audit->record('asset.depreciation_run', $posted, null, [
                'fiscal_period' => $open->name,
                'journal_no' => $posted->journal_no,
                'asset_count' => count($charges),
                'total' => $total->toDecimal(),
            ], $companyId);

            return $posted;
        });
    }

    /**
     * A period is depreciated once. Its own journal is the record of the run,
     * so a posted one means the period has been run; a reversed one does not,
     * because the reversal gave the charge back and the period is open again.
     */
    private function assertNotAlreadyRun(int $companyId, FiscalPeriod $period): void
    {
        $exists = Journal::query()
            ->where('company_id', $companyId)
            ->where('fiscal_period_id', $period->getKey())
            ->where('journal_type', JournalType::Depreciation->value)
            ->whereIn('status', [JournalStatus::Draft->value, JournalStatus::Posted->value])
            ->exists();

        if ($exists) {
            throw AssetStateException::periodAlreadyDepreciated($period);
        }
    }

    /**
     * What each asset in service owes the period, and nothing for those that
     * owe it nothing.
     *
     * An asset already depreciated through the period is skipped: the schedule
     * reads the accumulated depreciation on the row, so this can only be a
     * leftover from a run whose journal was reversed by hand, and charging it
     * twice is exactly what must not happen. The rows are locked for the rest
     * of the transaction, so two runs cannot both read the same accumulated
     * figure and both add to it.
     *
     * @return list<array{asset: Asset, amount: Money}>
     */
    private function charges(int $companyId, FiscalPeriod $period): array
    {
        $assets = Asset::query()
            ->where('company_id', $companyId)
            ->where('status', AssetStatus::InService->value)
            ->with('category')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $charges = [];

        foreach ($assets as $asset) {
            if ($asset->depreciated_through !== null
                && $asset->depreciated_through->greaterThanOrEqualTo($period->ends_on)) {
                continue;
            }

            $amount = $this->schedule->charge($asset, $period);

            if ($amount->isPositive()) {
                $charges[] = ['asset' => $asset, 'amount' => $amount];
            }
        }

        return $charges;
    }

    /**
     * One debit and one credit per asset, both described by it, so the entry
     * says which assets the period's charge was made of.
     *
     * @param  list<array{asset: Asset, amount: Money}>  $charges
     * @return array<int, JournalLineData>
     */
    private function lines(array $charges): array
    {
        $lines = [];

        foreach ($charges as $charge) {
            $asset = $charge['asset'];
            $description = $asset->code.' '.$asset->name;

            $lines[] = JournalLineData::debit(
                (int) $asset->category->depreciation_expense_account_id,
                $charge['amount'],
                $description,
            );

            $lines[] = JournalLineData::credit(
                (int) $asset->category->accumulated_depreciation_account_id,
                $charge['amount'],
                $description,
            );
        }

        return $lines;
    }

    /**
     * Writes what the run charged each asset and adds it to the asset's own
     * accumulated figure under a row_version guard, so an asset amended
     * underneath the run is refused rather than silently overwritten.
     *
     * The per-asset row is what a reversal reads to know what to give back: a
     * journal line names an account, and no account balance can say how much of
     * it belongs to one vehicle.
     *
     * @param  list<array{asset: Asset, amount: Money}>  $charges
     */
    private function recordCharges(
        int $companyId,
        FiscalPeriod $period,
        Journal $journal,
        array $charges,
    ): Money {
        $total = Money::zero();

        foreach ($charges as $charge) {
            $asset = $charge['asset'];
            $amount = $charge['amount'];

            AssetDepreciationLine::create([
                'company_id' => $companyId,
                'asset_id' => $asset->getKey(),
                'fiscal_period_id' => $period->getKey(),
                'journal_id' => $journal->getKey(),
                'amount' => $amount->toDecimal(),
            ]);

            $affected = Asset::query()
                ->whereKey($asset->getKey())
                ->where('row_version', $asset->row_version)
                ->update([
                    'accumulated_depreciation' => Money::of($asset->accumulated_depreciation)
                        ->plus($amount)
                        ->toDecimal(),
                    'depreciated_through' => $period->ends_on->toDateString(),
                    'row_version' => $asset->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Asset %s', $asset->code),
                    $asset->row_version,
                );
            }

            $total = $total->plus($amount);
        }

        return $total;
    }
}
