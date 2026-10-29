<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Enums\CashFlowCategory;
use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Classifies an account on the cash flow statement.
 *
 * This is the only door a category can be changed through, and it is a door
 * with a lock on it for a reason: the statement is built from these values, so
 * a change here silently restates how a period's cash movements are presented.
 * The chart of accounts is not a place where a quiet write is harmless, which is
 * why the change is stamped into the audit chain with the value it replaced
 * (see AuditTrail and the append-only chain behind it).
 *
 * Setting the category to the value it already holds is not a change, so it
 * writes nothing and records nothing. An audit log full of entries that record
 * no change is worse than no log: it buries the entries that matter.
 *
 * row_version is bumped in the same UPDATE that sets the category and the guard
 * is part of the WHERE clause, so two callers racing to classify the same
 * account cannot both believe they won. The loser's update matches no row and
 * is reported as a conflict rather than silently overwriting the winner.
 */
final class SetCashFlowCategory
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(Account $account, ?CashFlowCategory $category): Account
    {
        return DB::transaction(function () use ($account, $category): Account {
            $locked = Account::query()
                ->whereKey($account->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $before = $locked->cash_flow_category;

            if ($before === $category) {
                return $locked;
            }

            $affected = Account::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    'cash_flow_category' => $category?->value,
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Account %s', $locked->code),
                    (int) $locked->row_version,
                );
            }

            // The row is read back rather than reused, so the model handed to
            // the caller carries the row_version MySQL actually stored.
            $updated = Account::query()->findOrFail($locked->getKey());

            $this->audit->record('account.cash_flow_category_changed', $updated, [
                'cash_flow_category' => $before?->value,
            ], [
                'cash_flow_category' => $category?->value,
            ]);

            return $updated;
        });
    }
}
