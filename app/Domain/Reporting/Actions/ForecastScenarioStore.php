<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\ForecastScenario;
use App\Models\ForecastScenarioVersion;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Writes saved forecast scenarios, and is the only door onto their history.
 *
 * A scenario is a set of assumptions — a base, optimistic or pessimistic
 * stance, a revenue growth, an expense growth and a list of per-account
 * adjustments — and nothing else. Saving one posts no journal, moves no
 * balance and writes nothing to the ledger: the projection stays derived on
 * demand from the plan and the posted ledger, and a saved scenario only names
 * the assumptions a reader wants that projection re-stated under.
 *
 * The history is immutable, and this class is arranged around that. A scenario
 * row carries a pointer to the version that is live; a version is written once
 * and never edited, enforced by a database trigger as well as by this code.
 * Changing a scenario therefore writes the *next* version and moves the
 * pointer, and restoring an old version writes a *further* version that copies
 * it. Neither path rewinds: an old version is read, never consumed, so a reader
 * can always go back and see exactly what was saved and when.
 *
 * Every write is inside one transaction and stamps the audit chain in the same
 * transaction, so what changed, who changed it and what it was before commit or
 * roll back together. The pointer move is guarded on `row_version`, so two
 * Financial Managers revising the same scenario cannot both believe they wrote
 * the last word — the loser's update matches no row and is reported as a
 * conflict rather than silently overwriting the winner.
 */
final class ForecastScenarioStore
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Save a new scenario with its first version.
     *
     * @param  array{name: string, type: string, revenue_growth: string, expense_growth: string, adjustments?: array<int|string, string>, note?: ?string}  $data
     */
    public function create(int $companyId, int $userId, array $data): ForecastScenario
    {
        return DB::transaction(function () use ($companyId, $userId, $data): ForecastScenario {
            $scenario = new ForecastScenario([
                'company_id' => $companyId,
                'name' => (string) $data['name'],
                'type' => $data['type'],
                'current_version_no' => 1,
                'created_by_user_id' => $userId,
            ]);

            $scenario->save();

            $version = $this->writeVersion($scenario, 1, $userId, $data);

            $this->audit->record('forecast_scenario.created', $scenario, null, [
                'name' => (string) $scenario->name,
                'version_no' => 1,
                'assumptions' => $this->snapshot($version),
            ]);

            return $scenario->load('versions');
        });
    }

    /**
     * Change a scenario's assumptions by writing the next version.
     *
     * The name and type may be changed in the same act; the assumptions are
     * always written as a new version, never edited in place.
     *
     * @param  array{type: string, revenue_growth: string, expense_growth: string, adjustments?: array<int|string, string>, note?: ?string, name?: ?string}  $data
     */
    public function revise(ForecastScenario $scenario, int $userId, array $data): ForecastScenarioVersion
    {
        return DB::transaction(function () use ($scenario, $userId, $data): ForecastScenarioVersion {
            $locked = $this->lock($scenario);

            $before = $this->currentSnapshot($locked);
            $next = (int) $locked->current_version_no + 1;

            $version = $this->writeVersion($locked, $next, $userId, $data);

            $this->advance(
                $locked,
                $next,
                (string) $data['type'],
                isset($data['name']) ? (string) $data['name'] : null,
            );

            $this->audit->record('forecast_scenario.revised', $locked, $before, [
                'version_no' => $next,
                'assumptions' => $this->snapshot($version),
            ]);

            return $version;
        });
    }

    /**
     * Restore an old version by writing a new one that copies it.
     *
     * The version being restored is read and left exactly as it was: it is not
     * consumed, not deleted and not un-approved by this. What is written is a
     * further version carrying its assumptions forward, so the history shows
     * both what was saved then and that it was brought back now, and by whom.
     */
    public function restore(
        ForecastScenario $scenario,
        ForecastScenarioVersion $version,
        int $userId,
        ?string $note = null,
    ): ForecastScenarioVersion {
        return DB::transaction(function () use ($scenario, $version, $userId, $note): ForecastScenarioVersion {
            $locked = $this->lock($scenario);

            // Resolved inside the locked scenario, so a version belonging to
            // another scenario — or another company — cannot be restored here.
            $source = ForecastScenarioVersion::query()
                ->where('forecast_scenario_id', $locked->getKey())
                ->whereKey($version->getKey())
                ->firstOrFail();

            $before = $this->currentSnapshot($locked);
            $next = (int) $locked->current_version_no + 1;

            $restored = $this->writeVersion($locked, $next, $userId, [
                'type' => $source->type->value,
                'revenue_growth' => (string) $source->revenue_growth,
                'expense_growth' => (string) $source->expense_growth,
                'adjustments' => $source->adjustments ?? [],
                'note' => $note ?? sprintf('Restored from version %d', $source->version_no),
            ]);

            $this->advance($locked, $next, $source->type->value, null);

            $this->audit->record('forecast_scenario.restored', $locked, $before, [
                'version_no' => $next,
                'restored_from_version_no' => (int) $source->version_no,
                'assumptions' => $this->snapshot($restored),
            ]);

            return $restored;
        });
    }

    private function lock(ForecastScenario $scenario): ForecastScenario
    {
        return ForecastScenario::query()
            ->whereKey($scenario->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array{type: string, revenue_growth: string, expense_growth: string, adjustments?: array<int|string, string>, note?: ?string}  $data
     */
    private function writeVersion(ForecastScenario $scenario, int $versionNo, int $userId, array $data): ForecastScenarioVersion
    {
        return ForecastScenarioVersion::query()->create([
            'company_id' => (int) $scenario->company_id,
            'forecast_scenario_id' => $scenario->getKey(),
            'version_no' => $versionNo,
            'type' => $data['type'],
            'revenue_growth' => Money::of((string) $data['revenue_growth'])->toDecimal(),
            'expense_growth' => Money::of((string) $data['expense_growth'])->toDecimal(),
            'adjustments' => $this->adjustments($data['adjustments'] ?? []),
            'note' => $data['note'] ?? null,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Move the scenario's live pointer, and rename or retype it in the same
     * guarded update, so the scenario and its history cannot be left disagreeing.
     */
    private function advance(ForecastScenario $scenario, int $versionNo, string $type, ?string $name): void
    {
        $after = [
            'current_version_no' => $versionNo,
            'type' => $type,
            'row_version' => (int) $scenario->row_version + 1,
            'updated_at' => now(),
        ];

        if ($name !== null) {
            $after['name'] = $name;
        }

        $affected = ForecastScenario::query()
            ->whereKey($scenario->getKey())
            ->where('row_version', $scenario->row_version)
            ->update($after);

        if ($affected !== 1) {
            throw ConcurrentModificationException::row(
                sprintf('Forecast scenario %s', $scenario->name),
                (int) $scenario->row_version,
            );
        }
    }

    /**
     * Adjustment amounts normalised through Money and keyed by account, in
     * account order, so '100' and '100.0000' are the same adjustment and the
     * order the caller happened to send them in is not mistaken for a change.
     *
     * @param  array<int|string, string>  $adjustments
     * @return array<int, string>
     */
    private function adjustments(array $adjustments): array
    {
        $rows = [];

        foreach ($adjustments as $accountId => $amount) {
            $rows[(int) $accountId] = Money::of((string) $amount)->toDecimal();
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @return array{type: string, revenue_growth: string, expense_growth: string, adjustments: array<int, string>}
     */
    private function snapshot(ForecastScenarioVersion $version): array
    {
        return [
            'type' => $version->type->value,
            'revenue_growth' => (string) $version->revenue_growth,
            'expense_growth' => (string) $version->expense_growth,
            'adjustments' => $version->adjustments ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function currentSnapshot(ForecastScenario $scenario): array
    {
        $current = ForecastScenarioVersion::query()
            ->where('forecast_scenario_id', $scenario->getKey())
            ->where('version_no', $scenario->current_version_no)
            ->first();

        return $current === null ? [] : $this->snapshot($current);
    }
}
