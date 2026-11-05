<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Reporting\Enums\BudgetStatus;
use App\Domain\Reporting\Exceptions\BudgetStateException;
use App\Models\BudgetVersion;
use App\Models\BudgetVersionLine;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Writes versions of a company's plan, and is the only door onto them.
 *
 * A budget used to be a bare statement about one period with no history. It now
 * has a life: a draft that may be edited freely, a version raised for approval,
 * one that has been agreed to and one that has been closed. This class is where
 * a state is written and where the live plan is brought back into line with it.
 *
 * Two rules shape everything here:
 *
 *   The history is append-only. Once a version has been raised its amounts are
 *   frozen at the database — see the integrity triggers — so a change to a plan
 *   that has been agreed to writes the *next* version and leaves the agreed one
 *   exactly as it was. Only a draft is rewritten in place, and then only because
 *   nothing has been claimed about it yet.
 *
 *   The live plan is the newest version. The reports read `budgets` and
 *   `budget_lines`, which is where a plan has always lived, and this class is
 *   what keeps them equal to the version on the table: every write publishes the
 *   version it just wrote, in the same transaction, so an approval, a lock or a
 *   fresh draft can never leave the plan the reports show disagreeing with the
 *   history that explains it. Publishing goes through {@see SaveBudgetPlan}, so
 *   the live tables still have exactly one writer.
 *
 * Nothing here is accounting. Writing, publishing, approving and locking a plan
 * posts no journal, moves no balance and leaves the ledger untouched: a budget
 * is what the company intends, and only the variance report ever reads the two
 * together.
 */
final class BudgetVersionStore
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly SaveBudgetPlan $plans,
    ) {}

    /**
     * Write the plan for a fiscal year, as a new version or as a rewrite of the
     * draft that is already on the table.
     *
     * @param  list<array{fiscal_period_id: int|string, account_id: int|string, cost_center_id: int|string|null, amount: string}>  $lines
     */
    public function save(
        int $companyId,
        int $userId,
        FiscalYear $year,
        array $lines,
        ?string $name = null,
        ?string $reason = null,
        ?int $expectedRowVersion = null,
    ): BudgetVersion {
        return DB::transaction(function () use ($companyId, $userId, $year, $lines, $name, $reason, $expectedRowVersion): BudgetVersion {
            $latest = $this->latest($companyId, (int) $year->getKey());

            $plan = $this->canonical($lines);
            $before = $latest === null ? null : $this->snapshot($latest);

            $version = match (true) {
                $latest === null => $this->start($companyId, $userId, $year, $name, $reason),
                $latest->status === BudgetStatus::Draft => $this->rewrite($latest, $userId, $name, $reason, $expectedRowVersion),
                $latest->status === BudgetStatus::Approved => $this->start($companyId, $userId, $year, $name, $this->requireReason($latest, $reason)),
                $latest->status === BudgetStatus::PendingApproval => throw BudgetStateException::notEditable($latest),
                default => throw BudgetStateException::locked($latest),
            };

            $version = $this->writeLines($version, $plan);
            $this->publish($version);

            $this->audit->record('budget.version_saved', $version, $before, [
                'version_no' => (int) $version->version_no,
                'status' => $version->status->value,
                'reason' => $version->reason,
                'lines' => $plan,
            ]);

            return $version->load('lines');
        });
    }

    /**
     * Bring the live plan back into line with a version: every period of the
     * version's fiscal year is written to what the version says about it, and a
     * period the version says nothing about is cleared rather than left holding
     * the plan it used to have.
     *
     * Publishing is deliberately not a private step of save(): a decision or a
     * lock moves the standing of the plan and this has to be able to re-publish
     * it, so it is a named act with one owner rather than a side effect.
     */
    public function publish(BudgetVersion $version): void
    {
        $version->loadMissing('fiscalYear');

        $companyId = (int) $version->company_id;
        $lines = BudgetVersionLine::query()
            ->where('budget_version_id', $version->getKey())
            ->orderBy('fiscal_period_id')
            ->orderBy('account_id')
            ->get();

        $byPeriod = $lines->groupBy(static fn (BudgetVersionLine $line): int => (int) $line->fiscal_period_id);

        $periods = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $version->fiscal_year_id)
            ->orderBy('period_no')
            ->get();

        foreach ($periods as $period) {
            $periodLines = [];

            foreach ($byPeriod->get((int) $period->getKey(), collect()) as $line) {
                $periodLines[] = [
                    'account_id' => (int) $line->account_id,
                    'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                    'amount' => Money::of((string) $line->amount)->toDecimal(),
                ];
            }

            ($this->plans)($companyId, $period, $periodLines, $this->planName($version, $period));
        }
    }

    private function start(int $companyId, int $userId, FiscalYear $year, ?string $name, ?string $reason): BudgetVersion
    {
        // The next number in this year's history. It is read inside the caller's
        // transaction, where the newest version of the year is already locked, so
        // two concurrent writes cannot both claim version N — and the unique index
        // over (company, year, version_no) refuses the second one if they try.
        $next = 1 + (int) BudgetVersion::query()
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $year->getKey())
            ->max('version_no');

        $version = new BudgetVersion([
            'company_id' => $companyId,
            'fiscal_year_id' => $year->getKey(),
            'version_no' => $next,
            'status' => BudgetStatus::Draft,
            'name' => $name ?? $year->name,
            'reason' => $reason,
            'created_by_user_id' => $userId,
        ]);

        $version->save();

        return $version;
    }

    /**
     * Rewrite the draft that is already on the table.
     *
     * A draft is the only version whose amounts are not yet a claim about the
     * company, so it is the only one that is edited rather than superseded. The
     * update is guarded on row_version so two members editing the same draft
     * cannot both believe they wrote the last word.
     */
    private function rewrite(
        BudgetVersion $version,
        int $userId,
        ?string $name,
        ?string $reason,
        ?int $expectedRowVersion,
    ): BudgetVersion {
        if ($expectedRowVersion !== null && $expectedRowVersion !== (int) $version->row_version) {
            throw ConcurrentModificationException::row(
                sprintf('Budget version %d for %s', $version->version_no, $this->yearName($version)),
                $expectedRowVersion,
            );
        }

        $affected = BudgetVersion::query()
            ->whereKey($version->getKey())
            ->where('row_version', $version->row_version)
            ->update([
                'name' => $name ?? $version->name,
                'reason' => $reason ?? $version->reason,
                'created_by_user_id' => $userId,
                'row_version' => (int) $version->row_version + 1,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw ConcurrentModificationException::row(
                sprintf('Budget version %d for %s', $version->version_no, $this->yearName($version)),
                (int) $version->row_version,
            );
        }

        return BudgetVersion::query()->findOrFail($version->getKey());
    }

    /**
     * Replace a version's lines wholesale. A plan is a statement about a year
     * rather than a list that is appended to, so it is written as a whole.
     *
     * @param  list<array{fiscal_period_id: int, account_id: int, cost_center_id: int|null, amount: string}>  $plan
     */
    private function writeLines(BudgetVersion $version, array $plan): BudgetVersion
    {
        BudgetVersionLine::query()->where('budget_version_id', $version->getKey())->delete();

        foreach ($plan as $line) {
            BudgetVersionLine::query()->create([
                'company_id' => (int) $version->company_id,
                'budget_version_id' => $version->getKey(),
                'fiscal_period_id' => $line['fiscal_period_id'],
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'],
                'amount' => $line['amount'],
            ]);
        }

        return BudgetVersion::query()->findOrFail($version->getKey());
    }

    private function latest(int $companyId, int $fiscalYearId): ?BudgetVersion
    {
        return BudgetVersion::query()
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->orderByDesc('version_no')
            ->lockForUpdate()
            ->first();
    }

    private function requireReason(BudgetVersion $version, ?string $reason): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw BudgetStateException::reasonRequired($version);
        }

        return $reason;
    }

    /**
     * The lines as the caller asked for them, in a form two plans can be
     * compared in: amounts normalised through Money, so '1' and '1.0000' are the
     * same plan, and ordered, so the order the caller happened to send them in
     * is not mistaken for a change.
     *
     * @param  list<array{fiscal_period_id: int|string, account_id: int|string, cost_center_id: int|string|null, amount: string}>  $lines
     * @return list<array{fiscal_period_id: int, account_id: int, cost_center_id: int|null, amount: string}>
     */
    private function canonical(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                'fiscal_period_id' => (int) $line['fiscal_period_id'],
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
                'amount' => Money::of((string) $line['amount'])->toDecimal(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [
            $a['fiscal_period_id'], $a['account_id'], $a['cost_center_id'] ?? 0,
        ] <=> [
            $b['fiscal_period_id'], $b['account_id'], $b['cost_center_id'] ?? 0,
        ]);

        return $rows;
    }

    /**
     * @return array{version_no: int, status: string, reason: ?string, lines: list<array{fiscal_period_id: int, account_id: int, cost_center_id: int|null, amount: string}>}
     */
    private function snapshot(BudgetVersion $version): array
    {
        $rows = [];

        foreach (BudgetVersionLine::query()->where('budget_version_id', $version->getKey())->get() as $line) {
            $rows[] = [
                'fiscal_period_id' => (int) $line->fiscal_period_id,
                'account_id' => (int) $line->account_id,
                'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                'amount' => Money::of((string) $line->amount)->toDecimal(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [
            $a['fiscal_period_id'], $a['account_id'], $a['cost_center_id'] ?? 0,
        ] <=> [
            $b['fiscal_period_id'], $b['account_id'], $b['cost_center_id'] ?? 0,
        ]);

        return [
            'version_no' => (int) $version->version_no,
            'status' => $version->status->value,
            'reason' => $version->reason,
            'lines' => $rows,
        ];
    }

    /**
     * The name the live plan carries. A version covers a whole year while the
     * live tables are one row per period, so the plan's own name is qualified by
     * the period it is being written for.
     */
    private function planName(BudgetVersion $version, FiscalPeriod $period): string
    {
        return (string) ($version->name ?? $this->yearName($version)).' — '.$period->name;
    }

    private function yearName(BudgetVersion $version): string
    {
        $version->loadMissing('fiscalYear');

        return (string) $version->fiscalYear->name;
    }
}
