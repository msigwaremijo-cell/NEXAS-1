<?php

namespace App\Domain\Approvals\Services;

use App\Domain\Approvals\Enums\ApprovalDocumentType;
use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Banking\Enums\PaymentDirection;
use App\Models\ApprovalRequest;
use App\Models\Asset;
use App\Models\Bill;
use App\Models\Journal;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One queued document, read in full for the screen that decides it.
 *
 * The queue row carries only a handle. Everything a Financial Manager needs to
 * make a decision on it — what the document says, what it is worth, what is
 * filed beside it and how it got here — has to be gathered from the document's
 * own table, the attachment store and the audit chain. This service gathers all
 * of that once, for one request, and hands back a shape a screen can draw
 * without a dozen calls of its own.
 *
 * Nothing here posts anything or changes a document. It is a read: the preview
 * is the document's own lines, the impact is arithmetic over those lines, and
 * the warnings are observations — a document over the configured threshold, a
 * journal that does not balance, a request the reader raised themselves. A
 * warning is advice to the reader, never a gate; the server enforces the two
 * things that are gates — the no-self-approval rule and the bulk ceilings —
 * elsewhere.
 */
final class ApprovalDetailQuery
{
    /**
     * How long a document may wait before its age is called out. Long enough
     * that an ordinary week is not noisy, short enough that a month-old
     * decision is visibly late.
     */
    private const STALE_AFTER_DAYS = 14;

    /**
     * The whole of one queued document.
     *
     * @return array<string, mixed>
     */
    public function detail(ApprovalRequest $request, ?int $viewerId = null): array
    {
        $companyId = (int) $request->company_id;
        $type = $request->document_type;
        $document = $request->document();

        $impact = $this->impact($type, $document);
        $summary = $this->summary($request, $impact, $viewerId);

        return [
            'summary' => $summary,
            'preview' => $this->preview($type, $document),
            'financial_impact' => $impact,
            'attachments' => $this->attachments($companyId, $document),
            'timeline' => $this->timeline($request, $companyId),
            'warnings' => $this->warnings($request, $document, $summary, $impact, $viewerId),
            'document_missing' => $document === null,
        ];
    }

    /**
     * The facts read at the top of the detail: what the document is, who put
     * it here, who answered it, and whether the reader may answer it now.
     *
     * @param  array<string, mixed>  $impact
     * @return array<string, mixed>
     */
    private function summary(ApprovalRequest $request, array $impact, ?int $viewerId): array
    {
        $status = $request->status;
        $created = $request->created_at === null ? null : CarbonImmutable::parse((string) $request->created_at);
        $ageDays = $created === null ? null : max(0, (int) $created->diffInDays(CarbonImmutable::now()));

        $preparerId = $request->requested_by_user_id === null ? null : (int) $request->requested_by_user_id;
        $decidedById = $request->decided_by_user_id === null ? null : (int) $request->decided_by_user_id;

        return [
            'id' => (int) $request->getKey(),
            'ulid' => (string) $request->ulid,
            'document_type' => $request->document_type->value,
            'document_type_label' => $request->document_type->label(),
            'document_reference' => $request->document_reference,
            'document_ulid' => (string) $request->document_ulid,
            'status' => $request->status->value,
            'status_label' => $status->label(),
            'amount' => $impact['amount'],
            'currency_code' => $impact['currency_code'],
            'preparer_id' => $preparerId,
            'preparer_name' => $this->userName($preparerId),
            'decided_by_id' => $decidedById,
            'decided_by_name' => $this->userName($decidedById),
            'decision_reason' => $request->decision_reason,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'age_days' => $ageDays,
            'row_version' => (int) $request->row_version,
            'created_at' => $created?->toIso8601String(),
            // Whether this reader may decide it now. The server decides again
            // when the decision is made; this is only what the screen shows.
            'is_pending' => $status->isPending(),
            'raised_by_reader' => $viewerId !== null && $preparerId === $viewerId,
            'can_decide' => $viewerId !== null
                && $status->isPending()
                && $preparerId !== $viewerId,
        ];
    }

    /**
     * The document's own lines, shaped for a table. Fields are the header the
     * document carries; `lines` are its rows. Both are plain values so the
     * screen renders whatever the type gives it rather than one table layout
     * per document kind.
     *
     * @return array{kind: string, title: string, fields: list<array<string, string>>, lines: list<array<string, string>>}
     */
    private function preview(ApprovalDocumentType $type, ?Model $document): array
    {
        if ($document === null) {
            return ['kind' => $type->value, 'title' => $type->label(), 'fields' => [], 'lines' => []];
        }

        return match (true) {
            $document instanceof Journal => $this->journalPreview($document),
            $document instanceof Bill => $this->billPreview($document),
            $document instanceof Payment => $this->paymentPreview($document),
            $document instanceof Asset => $this->assetPreview($document),
            default => ['kind' => $type->value, 'title' => $type->label(), 'fields' => [], 'lines' => []],
        };
    }

    /**
     * @return array{kind: string, title: string, fields: list<array<string, string>>, lines: list<array<string, string>>}
     */
    private function journalPreview(Journal $journal): array
    {
        $lines = DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.journal_id', $journal->getKey())
            ->orderBy('jl.line_no')
            ->get([
                'jl.line_no',
                'a.code as account_code',
                'a.name as account_name',
                'jl.description',
                'jl.debit',
                'jl.credit',
            ])
            ->map(fn (object $row): array => [
                'line' => (string) $row->line_no,
                'account' => trim((string) $row->account_code.' '.$row->account_name),
                'description' => (string) ($row->description ?? ''),
                'debit' => (string) $row->debit,
                'credit' => (string) $row->credit,
            ])
            ->all();

        return [
            'kind' => 'journal',
            'title' => 'Journal '.($journal->journal_no ?? ''),
            'fields' => [
                ['label' => 'Journal number', 'value' => (string) ($journal->journal_no ?? '')],
                ['label' => 'Type', 'value' => $journal->journal_type->label()],
                ['label' => 'Status', 'value' => $journal->status->label()],
                ['label' => 'Posting date', 'value' => $journal->posting_date->toDateString()],
                ['label' => 'Reference', 'value' => (string) ($journal->reference ?? '')],
                ['label' => 'Memo', 'value' => (string) ($journal->memo ?? '')],
                ['label' => 'Currency', 'value' => (string) $journal->currency_code],
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @return array{kind: string, title: string, fields: list<array<string, string>>, lines: list<array<string, string>>}
     */
    private function billPreview(Bill $bill): array
    {
        $supplier = DB::table('suppliers')->where('id', $bill->supplier_id)->value('name');

        $lines = DB::table('bill_lines')
            ->where('bill_id', $bill->getKey())
            ->orderBy('line_no')
            ->get(['description', 'quantity', 'unit_price', 'line_total'])
            ->map(fn (object $row): array => [
                'description' => (string) $row->description,
                'quantity' => (string) $row->quantity,
                'unit price' => (string) $row->unit_price,
                'line total' => (string) $row->line_total,
            ])
            ->all();

        return [
            'kind' => 'bill',
            'title' => 'Bill '.($bill->bill_no ?? ''),
            'fields' => [
                ['label' => 'Bill number', 'value' => (string) ($bill->bill_no ?? '')],
                ['label' => 'Document', 'value' => (string) $bill->document_type],
                ['label' => 'Supplier', 'value' => (string) ($supplier ?? '')],
                ['label' => 'Status', 'value' => $bill->status->label()],
                ['label' => 'Bill date', 'value' => $bill->bill_date->toDateString()],
                ['label' => 'Due date', 'value' => $bill->due_date->toDateString()],
                ['label' => 'Subtotal', 'value' => (string) $bill->subtotal],
                ['label' => 'Tax', 'value' => (string) $bill->tax_total],
                ['label' => 'Total', 'value' => (string) $bill->total],
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @return array{kind: string, title: string, fields: list<array<string, string>>, lines: list<array<string, string>>}
     */
    private function paymentPreview(Payment $payment): array
    {
        $party = $this->partyName($payment->party_type->value, (int) $payment->party_id);
        $bank = $payment->bank_account_id === null
            ? null
            : DB::table('bank_accounts')->where('id', $payment->bank_account_id)->value('name');

        return [
            'kind' => 'payment',
            'title' => 'Payment '.($payment->payment_no ?? ''),
            'fields' => [
                ['label' => 'Payment number', 'value' => (string) ($payment->payment_no ?? '')],
                ['label' => 'Direction', 'value' => $payment->direction->label()],
                ['label' => 'Party', 'value' => (string) ($party ?? '')],
                ['label' => 'Method', 'value' => $payment->method->label()],
                ['label' => 'Payment date', 'value' => $payment->payment_date->toDateString()],
                ['label' => 'Bank account', 'value' => (string) ($bank ?? '')],
                ['label' => 'Reference', 'value' => (string) ($payment->reference ?? '')],
                ['label' => 'Amount', 'value' => (string) $payment->amount],
            ],
            'lines' => [],
        ];
    }

    /**
     * @return array{kind: string, title: string, fields: list<array<string, string>>, lines: list<array<string, string>>}
     */
    private function assetPreview(Asset $asset): array
    {
        return [
            'kind' => 'asset',
            'title' => 'Asset '.($asset->code ?? ''),
            'fields' => [
                ['label' => 'Code', 'value' => (string) ($asset->code ?? '')],
                ['label' => 'Name', 'value' => (string) ($asset->name ?? '')],
                ['label' => 'Status', 'value' => $asset->status->label()],
                ['label' => 'Acquisition cost', 'value' => (string) $asset->acquisition_cost],
                ['label' => 'Accumulated depreciation', 'value' => (string) $asset->accumulated_depreciation],
                ['label' => 'In service', 'value' => $asset->in_service_date?->toDateString() ?? ''],
                ['label' => 'Disposed on', 'value' => $asset->disposed_on?->toDateString() ?? ''],
                ['label' => 'Proceeds', 'value' => (string) ($asset->disposal_proceeds ?? '')],
            ],
            'lines' => [],
        ];
    }

    /**
     * What the document is worth and to whom, read the same way the queue reads
     * it so the figure beside a row and the figure in the detail agree.
     *
     * @return array<string, mixed>
     */
    private function impact(ApprovalDocumentType $type, ?Model $document): array
    {
        $threshold = (float) config('nexas.approvals.normal_threshold');

        $base = [
            'amount' => null,
            'currency_code' => 'TZS',
            'direction' => null,
            'party_name' => null,
            'threshold' => $threshold,
            'exceeds_threshold' => false,
            'debit_total' => null,
            'credit_total' => null,
            'balanced' => null,
            'subtotal' => null,
            'tax_total' => null,
            'net_book_value' => null,
            'gain_on_disposal' => null,
            'note' => 'The figure beside a queued document is the document\'s own total, read so a reader can recognise it. It is not a ledger balance and nothing here is posted.',
        ];

        if ($document === null) {
            return $base;
        }

        if ($document instanceof Journal) {
            $totals = DB::table('journal_lines')
                ->where('journal_id', $document->getKey())
                ->selectRaw('coalesce(sum(debit), 0) as debit_total')
                ->selectRaw('coalesce(sum(credit), 0) as credit_total')
                ->first();

            $debit = (string) ($totals->debit_total ?? '0');
            $credit = (string) ($totals->credit_total ?? '0');

            $base['amount'] = $debit;
            $base['currency_code'] = (string) $document->currency_code;
            $base['direction'] = 'debit';
            $base['debit_total'] = $debit;
            $base['credit_total'] = $credit;
            $base['balanced'] = bccomp($debit, $credit, 4) === 0;

            return $this->applyThreshold($base, $debit);
        }

        if ($document instanceof Bill) {
            $base['amount'] = (string) $document->total;
            $base['currency_code'] = (string) $document->currency_code;
            $base['direction'] = 'credit';
            $base['party_name'] = DB::table('suppliers')->where('id', $document->supplier_id)->value('name');
            $base['subtotal'] = (string) $document->subtotal;
            $base['tax_total'] = (string) $document->tax_total;

            return $this->applyThreshold($base, (string) $document->total);
        }

        if ($document instanceof Payment) {
            $base['amount'] = (string) $document->amount;
            $base['currency_code'] = (string) $document->currency_code;
            $base['direction'] = $document->direction === PaymentDirection::Receipt ? 'debit' : 'credit';
            $base['party_name'] = $this->partyName($document->party_type->value, (int) $document->party_id);

            return $this->applyThreshold($base, (string) $document->amount);
        }

        if ($document instanceof Asset) {
            $cost = (string) $document->acquisition_cost;
            $accumulated = (string) ($document->accumulated_depreciation ?? '0');
            $proceeds = (string) ($document->disposal_proceeds ?? '0');
            $netBookValue = bcsub($cost, $accumulated, 4);

            $base['amount'] = $proceeds;
            $base['direction'] = 'credit';
            $base['net_book_value'] = $netBookValue;
            $base['gain_on_disposal'] = bcsub($proceeds, $netBookValue, 4);

            return $this->applyThreshold($base, $netBookValue);
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return array<string, mixed>
     */
    private function applyThreshold(array $impact, string $amount): array
    {
        $impact['exceeds_threshold'] = bccomp($amount, (string) $impact['threshold'], 4) === 1;

        return $impact;
    }

    /**
     * The files filed against the document itself, scoped to the company. A
     * document in the queue is read as the record it names, so a receipt filed
     * beside the journal it corrects arrives here.
     *
     * @return list<array<string, mixed>>
     */
    private function attachments(int $companyId, ?Model $document): array
    {
        if ($document === null) {
            return [];
        }

        return DB::table('attachments')
            ->where('company_id', $companyId)
            ->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->getKey())
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['ulid', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by_user_id', 'created_at'])
            ->map(fn (object $row): array => [
                'ulid' => (string) $row->ulid,
                'file_name' => (string) $row->original_name,
                'mime_type' => $row->mime_type === null ? null : (string) $row->mime_type,
                'size_bytes' => (int) $row->size_bytes,
                'uploaded_by_user_id' => $row->uploaded_by_user_id === null
                    ? null
                    : (int) $row->uploaded_by_user_id,
                'created_at' => $row->created_at === null
                    ? null
                    : CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * How the request got here: the trail the queue wrote, oldest first. When
     * the trail is empty — a row seeded rather than raised — the two facts the
     * row itself carries stand in for it, so the screen is never blank.
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(ApprovalRequest $request, int $companyId): array
    {
        $rows = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->where('al.company_id', $companyId)
            ->where('al.auditable_type', $request->getMorphClass())
            ->where('al.auditable_id', $request->getKey())
            ->orderBy('al.created_at')
            ->orderBy('al.id')
            ->get(['al.action', 'al.user_id', 'u.name as user_name', 'al.after', 'al.created_at'])
            ->map(fn (object $row): array => [
                'action' => (string) $row->action,
                'label' => $this->actionLabel((string) $row->action),
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'user_name' => $row->user_name === null ? null : (string) $row->user_name,
                'at' => $row->created_at === null
                    ? null
                    : CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'detail' => $this->timelineDetail((string) $row->action, $row->after),
            ])
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        $fallback = [[
            'action' => 'approval.requested',
            'label' => 'Raised for approval',
            'user_id' => $request->requested_by_user_id === null ? null : (int) $request->requested_by_user_id,
            'user_name' => $this->userName($request->requested_by_user_id === null ? null : (int) $request->requested_by_user_id),
            'at' => $request->created_at?->toIso8601String(),
            'detail' => null,
        ]];

        if ($request->decided_at !== null) {
            $fallback[] = [
                'action' => $request->status === ApprovalStatus::Approved ? 'approval.approved' : 'approval.rejected',
                'label' => $request->status->label(),
                'user_id' => $request->decided_by_user_id === null ? null : (int) $request->decided_by_user_id,
                'user_name' => $this->userName($request->decided_by_user_id === null ? null : (int) $request->decided_by_user_id),
                'at' => $request->decided_at->toIso8601String(),
                'detail' => $request->decision_reason,
            ];
        }

        return $fallback;
    }

    /**
     * What the reader should be told about this document before deciding.
     *
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $impact
     * @return list<array{code: string, level: string, message: string}>
     */
    private function warnings(
        ApprovalRequest $request,
        ?Model $document,
        array $summary,
        array $impact,
        ?int $viewerId,
    ): array {
        $warnings = [];

        if ($document === null) {
            $warnings[] = [
                'code' => 'document_missing',
                'level' => 'bad',
                'message' => 'The document this request names can no longer be found in this company, so there is nothing to read or decide against.',
            ];
        }

        if ($summary['raised_by_reader'] === true) {
            $warnings[] = [
                'code' => 'self_approval',
                'level' => 'bad',
                'message' => 'You raised this document yourself, and nobody may approve their own entry. The server refuses the decision whoever asks.',
            ];
        }

        if ($summary['is_pending'] === false) {
            $warnings[] = [
                'code' => 'already_decided',
                'level' => 'info',
                'message' => 'This request already carries a decision, and a decision is final. A correction is raised as a new request.',
            ];
        }

        if (($impact['exceeds_threshold'] ?? false) === true && $impact['amount'] !== null) {
            $warnings[] = [
                'code' => 'over_threshold',
                'level' => 'warn',
                'message' => 'This document is above the configured review threshold of '
                    .number_format((float) $impact['threshold']).'. It may still be decided; it is flagged so it is decided on purpose.',
            ];
        }

        if ($document instanceof Journal && ($impact['balanced'] ?? true) === false) {
            $warnings[] = [
                'code' => 'unbalanced',
                'level' => 'bad',
                'message' => 'The journal does not balance: its total debits and total credits differ.',
            ];
        }

        if ($summary['is_pending'] === true
            && $summary['age_days'] !== null
            && $summary['age_days'] >= self::STALE_AFTER_DAYS
        ) {
            $warnings[] = [
                'code' => 'stale',
                'level' => 'warn',
                'message' => 'This document has been waiting '.$summary['age_days'].' days for a decision.',
            ];
        }

        return $warnings;
    }

    private function partyName(string $type, int $id): ?string
    {
        $table = match ($type) {
            'customer' => 'customers',
            'supplier' => 'suppliers',
            default => null,
        };

        if ($table === null) {
            return null;
        }

        $name = DB::table($table)->where('id', $id)->value('name');

        return is_string($name) ? $name : null;
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return is_string($name) ? $name : null;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'approval.requested' => 'Raised for approval',
            'approval.approved' => 'Approved',
            'approval.rejected' => 'Rejected',
            default => $action,
        };
    }

    private function timelineDetail(string $action, mixed $after): ?string
    {
        if (! is_string($after) || $after === '') {
            return null;
        }

        $decoded = json_decode($after, true);

        if (! is_array($decoded)) {
            return null;
        }

        if ($action === 'approval.rejected' && isset($decoded['decision_reason'])) {
            return is_string($decoded['decision_reason']) ? $decoded['decision_reason'] : null;
        }

        return null;
    }
}
