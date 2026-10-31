<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Assets\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\AssetDepreciationLine;
use App\Models\FiscalPeriod;
use App\Models\Journal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a period's depreciation run the only way a ledger allows: by posting
 * the opposite entry, not by deleting the first one.
 *
 * The journal is reversed through ReverseJournal, so it takes its own number,
 * has to balance and lands in an open period like any other correction, and the
 * original moves posted -> reversed rather than disappearing.
 *
 * What the reversal has to add is the register's half. The run charged each
 * asset and moved its own accumulated figure; reversing the journal moves the
 * accounts back but leaves the rows saying the period was still charged. The
 * run's per-asset lines are therefore read for what to give back, the assets
 * are returned to what they held before, and the lines are deleted — which is
 * what reopens the period: the next run finds no journal for it and no line
 * standing in the way of UNIQUE(asset_id, fiscal_period_id).
 *
 * Giving an asset back its accumulated figure is not the same as setting it to
 * zero: an asset charged over several periods keeps everything except the
 * period being undone, so what remains is read from the lines that are left.
 */
final class ReverseDepreciation
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        int $companyId,
        FiscalPeriod $period,
        ?string $postingDate = null,
        ?string $memo = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Journal {
        return DB::transaction(function () use (
            $companyId,
            $period,
            $postingDate,
            $memo,
            $userId,
            $idempotencyKey
        ): Journal {
            // Scoped to the company in the query that finds it, so another
            // tenant's period cannot be undone against these books.
            $scoped = FiscalPeriod::query()
                ->where('company_id', $companyId)
                ->whereKey($period->getKey())
                ->firstOrFail();

            $original = $this->journalFor($companyId, $scoped);

            if ($original === null) {
                throw AssetStateException::periodNotDepreciated($scoped);
            }

            $reversal = ($this->reverseJournal)($original, $postingDate, $memo, $userId, $idempotencyKey);

            $givenBack = $this->giveBack($companyId, $original);

            $this->audit->record('asset.depreciation_reversed', $original, [
                'status' => JournalStatus::Posted->value,
            ], [
                'status' => JournalStatus::Reversed->value,
                'reversal_journal_no' => $reversal->journal_no,
                'asset_count' => $givenBack['count'],
                'total' => $givenBack['total']->toDecimal(),
            ]);

            return $reversal;
        });
    }

    /**
     * The period's own run, if one is still standing. A reversed journal is not
     * found: the period has already been given back.
     */
    private function journalFor(int $companyId, FiscalPeriod $period): ?Journal
    {
        return Journal::query()
            ->where('company_id', $companyId)
            ->where('fiscal_period_id', $period->getKey())
            ->where('journal_type', JournalType::Depreciation->value)
            ->where('status', JournalStatus::Posted->value)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{count: int, total: Money}
     */
    private function giveBack(int $companyId, Journal $journal): array
    {
        $lines = AssetDepreciationLine::query()
            ->where('company_id', $companyId)
            ->where('journal_id', $journal->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $total = Money::zero();

        foreach ($lines as $line) {
            $asset = Asset::query()
                ->whereKey($line->asset_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restored = Money::of($asset->accumulated_depreciation)->minus(Money::of($line->amount));

            if ($restored->isNegative()) {
                $restored = Money::zero();
            }

            $affected = Asset::query()
                ->whereKey($asset->getKey())
                ->where('row_version', $asset->row_version)
                ->update([
                    'accumulated_depreciation' => $restored->toDecimal(),
                    'depreciated_through' => $this->throughAfter($asset->getKey(), $journal->getKey()),
                    'row_version' => $asset->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Asset %s', $asset->code),
                    $asset->row_version,
                );
            }

            $total = $total->plus(Money::of($line->amount));
        }

        AssetDepreciationLine::query()
            ->where('journal_id', $journal->getKey())
            ->delete();

        return ['count' => $lines->count(), 'total' => $total];
    }

    /**
     * The end of the last period still charged to the asset once this run is
     * taken out of the picture.
     */
    private function throughAfter(int $assetId, int $journalId): ?string
    {
        $remaining = AssetDepreciationLine::query()
            ->where('asset_id', $assetId)
            ->where('journal_id', '!=', $journalId)
            ->with('fiscalPeriod')
            ->get()
            ->map(static fn (AssetDepreciationLine $line): ?string => $line->fiscalPeriod?->ends_on?->toDateString())
            ->filter()
            ->sort()
            ->values();

        return $remaining->isEmpty() ? null : $remaining->last();
    }
}
