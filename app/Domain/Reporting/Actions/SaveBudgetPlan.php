<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FiscalPeriod;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Writes the plan for one fiscal period, and is the only door onto a budget.
 *
 * A plan is written as a whole rather than a line at a time, because it is a
 * statement about a period rather than a list that is appended to: the caller
 * says what the company means to earn and spend, and the plan becomes that.
 * There is deliberately no per-line edit route, which is also why the lines are
 * replaced inside one transaction rather than tracked individually.
 *
 * Writing a plan posts nothing. No journal is created, no balance moves and no
 * report built from the ledger changes: a budget is what the company intends,
 * and only this feature — the variance report — ever reads the two together.
 *
 * Every change is stamped into the audit chain with what the plan held before,
 * in the same transaction as the change itself, so an audit record and the
 * change it describes commit or roll back together. Writing a plan that is
 * already what the caller asked for is not a change: it writes nothing and
 * records nothing, because an audit log full of entries that record no change
 * buries the entries that matter.
 *
 * row_version is bumped in the same UPDATE that renames the plan and the guard
 * is part of the WHERE clause, so two members rewriting the same plan cannot
 * both believe they wrote the last word. The loser's update matches no row and
 * is reported as a conflict rather than silently overwriting the winner.
 *
 * The caller is the boundary: the accounts and cost centres the lines name are
 * validated there as belonging to the company, before this is reached. Nothing
 * here re-checks it, because every caller is inside the company middleware.
 */
final class SaveBudgetPlan
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Create the plan for a period, or replace the one it already has.
     *
     * @param  list<array{account_id: int|string, cost_center_id: int|string|null, amount: string}>  $lines
     */
    public function __invoke(
        int $companyId,
        FiscalPeriod $period,
        array $lines,
        ?string $name = null,
    ): Budget {
        return DB::transaction(function () use ($companyId, $period, $lines, $name): Budget {
            $budget = Budget::query()
                ->where('company_id', $companyId)
                ->where('fiscal_period_id', $period->getKey())
                ->lockForUpdate()
                ->first();

            $plan = $this->canonical($lines);
            $before = $budget === null ? null : $this->snapshot($budget);
            $requestedName = $name ?? $budget?->name ?? $period->name;

            if ($before !== null && $before['name'] === $requestedName && $before['lines'] === $plan) {
                return $budget->load('lines');
            }

            if ($budget === null) {
                $budget = new Budget([
                    'company_id' => $companyId,
                    'fiscal_period_id' => $period->getKey(),
                    'name' => $requestedName,
                ]);

                $budget->save();
            } else {
                $affected = Budget::query()
                    ->whereKey($budget->getKey())
                    ->where('row_version', $budget->row_version)
                    ->update([
                        'name' => $requestedName,
                        'row_version' => $budget->row_version + 1,
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw ConcurrentModificationException::row(
                        sprintf('Budget for %s', $period->name),
                        (int) $budget->row_version,
                    );
                }
            }

            BudgetLine::query()->where('budget_id', $budget->getKey())->delete();

            foreach ($plan as $line) {
                BudgetLine::query()->create([
                    'company_id' => $companyId,
                    'budget_id' => $budget->getKey(),
                    'account_id' => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'],
                    'amount' => $line['amount'],
                ]);
            }

            // Read back rather than reused, so the model handed to the caller
            // carries the row_version MySQL actually stored.
            $updated = Budget::query()->findOrFail($budget->getKey())->load('lines');

            $this->audit->record('budget.plan_saved', $updated, [
                'name' => $before['name'] ?? null,
                'lines' => $before['lines'] ?? [],
            ], [
                'name' => $requestedName,
                'lines' => $plan,
            ]);

            return $updated;
        });
    }

    /**
     * The lines as the caller asked for them, in a form two plans can be
     * compared in: amounts normalised through Money, so '1' and '1.0000' are
     * the same plan, and ordered by account and cost centre, so the order the
     * caller happened to send them in is not mistaken for a change.
     *
     * @param  list<array{account_id: int|string, cost_center_id: int|string|null, amount: string}>  $lines
     * @return list<array{account_id: int, cost_center_id: int|null, amount: string}>
     */
    private function canonical(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] === null ? null : (int) $line['cost_center_id'],
                'amount' => Money::of((string) $line['amount'])->toDecimal(),
            ];
        }

        usort($rows, [self::class, 'compareLines']);

        return $rows;
    }

    /**
     * The plan as it stands, in the same form as canonical().
     *
     * @return array{name: string, lines: list<array{account_id: int, cost_center_id: int|null, amount: string}>}
     */
    private function snapshot(Budget $budget): array
    {
        $rows = [];

        foreach (BudgetLine::query()->where('budget_id', $budget->getKey())->get() as $line) {
            $rows[] = [
                'account_id' => (int) $line->account_id,
                'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                'amount' => Money::of((string) $line->amount)->toDecimal(),
            ];
        }

        usort($rows, [self::class, 'compareLines']);

        return ['name' => (string) $budget->name, 'lines' => $rows];
    }

    /**
     * @param  array{account_id: int, cost_center_id: int|null, amount: string}  $a
     * @param  array{account_id: int, cost_center_id: int|null, amount: string}  $b
     */
    private static function compareLines(array $a, array $b): int
    {
        return [$a['account_id'], $a['cost_center_id'] ?? 0]
            <=> [$b['account_id'], $b['cost_center_id'] ?? 0];
    }
}
