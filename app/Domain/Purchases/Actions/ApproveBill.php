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

            // Approval is a control, not a second signature by the same hand,
            // so it is refused whoever asks — including the Owner, and
            // regardless of which grants the caller holds. The bill names its
            // own author, so the check is a comparison rather than a role test.
            if ($approverId !== null && $locked->created_by_user_id === $approverId) {
                throw BillStateException::selfApprovalRefused($locked);
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
                throw ConcurrentModificationException::row(
                    sprintf('Bill %s', $locked->bill_no),
                    (int) $locked->row_version
                );
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
