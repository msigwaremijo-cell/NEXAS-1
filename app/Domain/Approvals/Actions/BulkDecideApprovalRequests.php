<?php

namespace App\Domain\Approvals\Actions;

use App\Domain\Accounting\Exceptions\AccountingException;
use App\Domain\Approvals\Exceptions\ApprovalsException;
use App\Domain\Approvals\Services\ApprovalQueueQuery;
use App\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Decides several queued documents in one answer.
 *
 * A bulk approval is a fan-out, not a second kind of decision: each document is
 * decided by the same action a single approval goes through, so the no-self-
 * approval rule, the finality of a decision and the audit link are the same for
 * every item. What this class adds is the two ceilings the batch works under
 * and a per-item answer, so one document the server refuses does not discard
 * the ones it accepted.
 *
 * Both ceilings are read from configuration and checked against the documents
 * the batch really names, read inside the caller's company — never against the
 * count or the value the screen drew — so neither can be raised by editing what
 * is sent. They are pre-flight: a batch that is too large or too valuable is
 * refused whole, before anything in it is decided.
 *
 * Each item is keyed from the batch's own idempotency key and the document's
 * ULID, so a retried batch replays every decision it already made rather than
 * deciding a document twice. The derivation is deterministic, which is what
 * makes the retry produce the same keys.
 */
final class BulkDecideApprovalRequests
{
    public function __construct(
        private readonly DecideApprovalRequest $decide,
        private readonly ApprovalQueueQuery $queue,
    ) {}

    /**
     * @param  list<string>  $ulids
     * @return array<string, mixed>
     */
    public function approve(
        int $companyId,
        array $ulids,
        ?int $decidedByUserId,
        string $idempotencyKey,
    ): array {
        // Stable and de-duplicated: a document named twice is one decision, and
        // the order the caller sent does not change the batch's identity.
        $ulids = array_values(array_unique(array_filter(
            array_map(fn (mixed $ulid): string => trim((string) $ulid), $ulids),
            fn (string $ulid): bool => $ulid !== '',
        )));

        $maxItems = (int) config('nexas.approvals.bulk_max_items');

        if (count($ulids) > $maxItems) {
            throw ApprovalsException::bulkTooManyItems(count($ulids), $maxItems);
        }

        // Read through the queue's own query, so an item's value is measured
        // the same way it is shown, and a ULID belonging to another company
        // simply does not appear.
        $lines = $this->queue->lines($companyId, $ulids);

        $limit = (float) config('nexas.approvals.bulk_limit');
        $value = $this->value($lines);

        if (bccomp($value, (string) $limit, 4) === 1) {
            throw ApprovalsException::bulkOverLimit($value, $limit);
        }

        $results = [];
        $approved = 0;
        $approvedValue = '0';

        foreach ($ulids as $ulid) {
            $result = $this->decideOne($companyId, $ulid, $lines[$ulid] ?? null, $decidedByUserId, $idempotencyKey);

            $results[] = $result;

            if ($result['status'] === 'approved') {
                $approved++;
                $approvedValue = bcadd($approvedValue, (string) ($result['amount'] ?? '0'), 4);
            }
        }

        return [
            'requested' => count($ulids),
            'approved' => $approved,
            'failed' => count($ulids) - $approved,
            'value' => $approvedValue,
            'results' => $results,
        ];
    }

    /**
     * One document, decided with the same rules a single approval meets.
     *
     * A refusal is recorded as this item's answer rather than thrown, so the
     * rest of the batch is decided and the caller is told, item by item, what
     * happened and why.
     *
     * @param  array<string, mixed>|null  $line
     * @return array<string, mixed>
     */
    private function decideOne(
        int $companyId,
        string $ulid,
        ?array $line,
        ?int $decidedByUserId,
        string $idempotencyKey,
    ): array {
        $request = ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('ulid', $ulid)
            ->first();

        if ($request === null) {
            return $this->refused($ulid, $line, 'This request is not in the queue for this company.');
        }

        try {
            $decided = $this->decide->approve($request, $decidedByUserId, $this->itemKey($idempotencyKey, $ulid));

            return [
                'ulid' => $ulid,
                'document_type' => $decided->document_type->value,
                'document_type_label' => $decided->document_type->label(),
                'document_reference' => $decided->document_reference,
                'amount' => $line['amount'] ?? null,
                'status' => 'approved',
                'message' => null,
            ];
        } catch (AccountingException|ModelNotFoundException $refusal) {
            return $this->refused($ulid, $line, $refusal->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $line
     * @return array<string, mixed>
     */
    private function refused(string $ulid, ?array $line, string $message): array
    {
        return [
            'ulid' => $ulid,
            'document_type' => $line['document_type'] ?? null,
            'document_type_label' => $line['document_type_label'] ?? null,
            'document_reference' => $line['document_reference'] ?? null,
            'amount' => $line['amount'] ?? null,
            'status' => 'failed',
            'message' => $message,
        ];
    }

    /**
     * The item key a batch uses for one document.
     *
     * Deterministic from the batch key and the ULID, so a retried batch derives
     * the same key for the same document and replays that decision instead of
     * deciding it again. Kept inside the `key` column's own width.
     */
    private function itemKey(string $bulkKey, string $ulid): string
    {
        return 'bulk-'.substr(hash('sha256', $bulkKey.'|'.$ulid), 0, 40);
    }

    /**
     * The summed value of the documents the batch names.
     *
     * Decimal arithmetic, so the ceiling is compared the way money is
     * elsewhere, and an item whose amount is unknown contributes nothing.
     *
     * @param  array<string, array<string, mixed>>  $lines
     */
    private function value(array $lines): string
    {
        $total = '0';

        foreach ($lines as $line) {
            $amount = $line['amount'] ?? null;

            if ($amount !== null && $amount !== '') {
                $total = bcadd($total, (string) $amount, 4);
            }
        }

        return $total;
    }
}
