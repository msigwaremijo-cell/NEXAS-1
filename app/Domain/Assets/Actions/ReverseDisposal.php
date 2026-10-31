<?php

namespace App\Domain\Assets\Actions;

use App\Domain\Accounting\Actions\ReverseJournal;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Assets\Enums\AssetStatus;
use App\Domain\Assets\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\Journal;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a disposal the only way a ledger allows: by posting the opposite
 * entry, not by editing the disposal.
 *
 * The disposal journal is reversed through ReverseJournal, so the cost, the
 * accumulated depreciation, the proceeds and the gain or loss all go back where
 * they came from in one balanced entry that lands in an open period, and the
 * original moves posted -> reversed rather than disappearing.
 *
 * What the reversal has to add is the register's half. The journal puts the
 * accounts back, but the asset row still says disposed and still names the
 * journal that disposed of it. The row is therefore returned to service with
 * its disposal fields cleared, which is what lets it be depreciated, amended and
 * disposed of again — the state a disposal removed.
 *
 * Nothing is done to the asset's cost or accumulated depreciation. A disposal
 * never changed them: it takes the asset out of the books, and the register
 * keeps what it was. Bringing it back is therefore the row moving, and nothing
 * else.
 */
final class ReverseDisposal
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(
        int $companyId,
        Asset $asset,
        ?string $postingDate = null,
        ?string $memo = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): Journal {
        return DB::transaction(function () use (
            $companyId,
            $asset,
            $postingDate,
            $memo,
            $userId,
            $idempotencyKey
        ): Journal {
            // Scoped to the company in the query that finds it, so another
            // tenant's asset cannot be brought back against these books.
            $locked = Asset::query()
                ->where('company_id', $companyId)
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isDisposed() || $locked->disposal_journal_id === null) {
                throw AssetStateException::notDisposed($locked);
            }

            $original = Journal::query()
                ->where('company_id', $companyId)
                ->whereKey($locked->disposal_journal_id)
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                throw AssetStateException::notDisposed($locked);
            }

            $reversal = ($this->reverseJournal)(
                $original,
                $postingDate,
                $memo ?? 'Reversal of disposal: '.$locked->code.' '.$locked->name,
                $userId,
                $idempotencyKey,
            );

            $expected = $locked->row_version;

            $affected = Asset::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $expected)
                ->update([
                    'status' => AssetStatus::InService->value,
                    'disposed_on' => null,
                    'disposal_proceeds' => null,
                    'disposal_journal_id' => null,
                    'row_version' => $expected + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Asset %s', $locked->code),
                    $expected,
                );
            }

            $restored = Asset::query()->findOrFail($locked->getKey());

            $this->audit->record('asset.disposal_reversed', $restored, [
                'status' => AssetStatus::Disposed->value,
                'disposed_on' => $locked->disposed_on?->toDateString(),
                'disposal_proceeds' => $locked->disposal_proceeds,
            ], [
                'status' => AssetStatus::InService->value,
                'reversal_journal_no' => $reversal->journal_no,
            ]);

            return $reversal;
        });
    }
}
