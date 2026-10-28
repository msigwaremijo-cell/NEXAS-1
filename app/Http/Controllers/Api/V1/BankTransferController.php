<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\TransferBetweenAccounts;
use App\Domain\Banking\DTOs\TransferData;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moving money between two of the company's own cash or bank accounts.
 *
 * The whole of the accounting lives in TransferBetweenAccounts: which two
 * ledger accounts the entry lands on, how the journal is built, and how a
 * retried transfer is answered with the original rather than posted twice.
 *
 * Both ids are checked against the caller's own company rather than merely
 * existing, so naming another tenant's account is a validation error and not a
 * way of finding out that it exists. The action resolves both rows again inside
 * the transaction and refuses an account that is closed, parked or deleted:
 * the controller's rule is about the shape of the request, the action's is
 * about the state of the books.
 *
 * Nothing here talks to a bank. Both accounts are rows a person created in this
 * system; the transfer is a journal written in the books.
 */
class BankTransferController extends Controller
{
    public function store(Request $request, TransferBetweenAccounts $transfer): JsonResponse
    {
        $this->authorize('transfer', BankAccount::class);

        $companyId = $this->companyId($request);

        $data = $request->validate([
            'from_bank_account_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId),
            ],
            'to_bank_account_id' => [
                'required', 'integer', 'min:1',
                Rule::exists('bank_accounts', 'id')->where('company_id', $companyId),
            ],
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            // A decimal string, never a JSON number with a fraction: a float
            // cannot round-trip DECIMAL(19,4). "0" is accepted here and refused
            // by the action, which is where the rule about amounts lives.
            'amount' => ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $fromBankAccountId = (int) $data['from_bank_account_id'];
        $toBankAccountId = (int) $data['to_bank_account_id'];

        $journal = $transfer(new TransferData(
            companyId: $companyId,
            fromBankAccountId: $fromBankAccountId,
            toBankAccountId: $toBankAccountId,
            transferDate: $data['transfer_date'],
            amount: $data['amount'],
            reference: $data['reference'] ?? null,
            userId: $request->user()?->getKey(),
            idempotencyKey: $this->idempotencyKey($request),
        ));

        return response()->json([
            'data' => $this->present($journal, $fromBankAccountId, $toBankAccountId),
        ], 201);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Journal $journal, int $fromBankAccountId, int $toBankAccountId): array
    {
        return [
            'journal_id' => (int) $journal->getKey(),
            'journal_ulid' => $journal->ulid,
            'journal_no' => $journal->journal_no,
            'journal_type' => $journal->journal_type->value,
            'status' => $journal->status->value,
            'posting_date' => $journal->posting_date?->toDateString(),
            'currency_code' => $journal->currency_code,
            'from_bank_account_id' => $fromBankAccountId,
            'to_bank_account_id' => $toBankAccountId,
            'reference' => $journal->reference,
            'memo' => $journal->memo,
            'total_debit' => $journal->total_debit,
            'total_credit' => $journal->total_credit,
            'lines' => array_map(fn (JournalLine $line): array => [
                'line_no' => (int) $line->line_no,
                'account_id' => (int) $line->account_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
            ], $journal->lines->all()),
        ];
    }

    private function companyId(Request $request): int
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return (int) $companyId;
    }
}
