<?php

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use Illuminate\Support\Facades\DB;

/**
 * Moves a draft bill behind the approval gate.
 *
 * Submitting does not approve anything: it moves draft -> pending_approval and
 * records that approval is now owed. The separation exists so the member who
 * raises a bill is not necessarily the member who authorises it, which is the
 * whole point of an approval gate in a payables ledger.
 */
final class SubmitBillForApproval
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(Bill $bill, ?int $userId = null): Bill
    {
        return DB::transaction(function () use ($bill, $userId): Bill {
            $locked = Bill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw BillStateException::notDraft($locked, $locked->status);
            }

            if ($locked->lines()->count() === 0) {
                throw BillStateException::withoutLines($locked);
            }

            $affected = Bill::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    'status' => BillStatus::PendingApproval->value,
                    'approval_status' => 'pending',
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::row(
                    sprintf('Bill %s', $locked->bill_no),
                    (int) $locked->row_version,
                );
            }

            $submitted = Bill::query()->findOrFail($locked->getKey());

            $this->audit->record('bill.submitted', $submitted, [
                'status' => BillStatus::Draft->value,
            ], [
                'status' => BillStatus::PendingApproval->value,
                'submitted_by_user_id' => $userId,
            ]);

            return $submitted;
        });
    }
}
