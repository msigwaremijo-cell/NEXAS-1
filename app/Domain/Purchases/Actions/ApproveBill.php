<?php

namespace App\Domain\Purchases\Actions;

use App\Domain\Accounting\Exceptions\ConcurrentModificationException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Purchases\Exceptions\BillStateException;
use App\Models\Bill;
use Illuminate\Support\Facades\DB;

/**
 * Moves a bill pending approval to approved, naming the member who did it.
 *
 * The approver is written to the bill itself, not just to the audit chain, so
 * the payables ledger can answer "who authorised this liability" without a
 * join. Approval is the only move that unlocks posting.
 */
final class ApproveBill
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(Bill $bill, ?int $approverId = null): Bill
    {
        return DB::transaction(function () use ($bill, $approverId): Bill {
            $locked = Bill::query()
                ->whereKey($bill->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== BillStatus::PendingApproval) {
                throw BillStateException::notAwaitingApproval($locked, $locked->status);
            }

            $affected = Bill::query()
                ->whereKey($locked->getKey())
                ->where('row_version', $locked->row_version)
                ->update([
                    'status' => BillStatus::Approved->value,
                    'approval_status' => 'approved',
                    'approved_at' => now(),
                    'approved_by_user_id' => $approverId,
                    'row_version' => $locked->row_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw ConcurrentModificationException::journal($locked);
            }

            $approved = Bill::query()->findOrFail($locked->getKey());

            $this->audit->record('bill.approved', $approved, [
                'status' => BillStatus::PendingApproval->value,
                'approval_status' => 'pending',
            ], [
                'status' => BillStatus::Approved->value,
                'approval_status' => 'approved',
                'approved_by_user_id' => $approverId,
            ]);

            return $approved;
        });
    }
}
