<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Banking\DTOs\TransferData;
use App\Domain\Banking\Exceptions\TransferStateException;
use App\Models\BankAccount;
use App\Models\Journal;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Moves money between two of the company's own cash or bank accounts.
 *
 * A transfer is one balanced journal and nothing else:
 *
 *   Dr  the account the money arrived in   the amount
 *   Cr  the account the money left         the amount
 *
 * Both ledger accounts are derived from the two `bank_accounts` rows the caller
 * named, so a transfer can only ever move money between accounts the company
 * has opened, and neither side is allowed to be an account that has been
 * closed, parked or deleted.
 *
 * It is deliberately not a receipt and not a payment. No third party is
 * involved, nothing is owed or settled, and the entry cancels itself out across
 * the company's own accounts: which is why it is posted as JournalType::Transfer
 * and can be left out of a cash flow statement rather than appearing there as
 * an inflow and an outflow that never happened.
 *
 * The journal goes through CreateJournal and PostJournal like every other
 * posting, so a transfer obeys the same period lock, balance check, gapless
 * numbering and immutability trigger. A caller that passes an idempotency key
 * gets the original transfer back instead of a second one: the key is written
 * onto the journal row, where the unique index on (company_id, idempotency_key)
 * is what actually makes the retry safe.
 */
final class TransferBetweenAccounts
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(TransferData $data): Journal
    {
        return DB::transaction(function () use ($data): Journal {
            $amount = Money::of($data->amount);

            if (! $amount->isPositive()) {
                throw TransferStateException::nonPositiveAmount();
            }

            if ($data->fromBankAccountId === $data->toBankAccountId) {
                throw TransferStateException::sameAccount($data->fromBankAccountId);
            }

            $from = $this->bankAccount($data->companyId, $data->fromBankAccountId, 'source');
            $to = $this->bankAccount($data->companyId, $data->toBankAccountId, 'destination');

            // A retried transfer is answered with the journal the first attempt
            // posted. The lookup is on the journal row rather than on the
            // idempotency_keys table because PostJournal writes the caller's key
            // onto the journal and the database enforces it unique per company,
            // so the row found here is the one the key belongs to.
            $replay = $data->idempotencyKey === null
                ? null
                : Journal::query()
                    ->where('company_id', $data->companyId)
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

            if ($replay !== null) {
                return $replay->load('lines');
            }

            $memo = 'Transfer: '.$from->name.' to '.$to->name;

            $journal = ($this->create)(new JournalEntryData(
                companyId: $data->companyId,
                postingDate: $data->transferDate,
                journalType: JournalType::Transfer,
                lines: [
                    JournalLineData::debit((int) $to->account_id, $amount, $memo),
                    JournalLineData::credit((int) $from->account_id, $amount, $memo),
                ],
                reference: $data->reference,
                memo: $memo,
                documentDate: $data->transferDate,
                // The journal is the transfer; there is no separate transfer
                // document to point at, so the source account is recorded as
                // what raised it.
                sourceType: BankAccount::class,
                sourceId: (int) $from->getKey(),
                createdByUserId: $data->userId,
            ));

            $posted = ($this->post)($journal, $data->idempotencyKey, $data->userId);

            $this->audit->record('bank.transfer.posted', $posted, null, [
                'journal_no' => $posted->journal_no,
                'from_bank_account_id' => (int) $from->getKey(),
                'to_bank_account_id' => (int) $to->getKey(),
                'amount' => $amount->toDecimal(),
            ]);

            return $posted->load('lines');
        });
    }

    /**
     * One side of the transfer, resolved inside the tenant.
     *
     * Scoped to the company in the same query, so an id from another tenant
     * finds nothing. Soft-deleted accounts are excluded by the model scope.
     * Only an active account may take part: a closed account keeps its history
     * but must not be handed money, and an inactive one is parked rather than
     * in use.
     */
    private function bankAccount(int $companyId, int $bankAccountId, string $side): BankAccount
    {
        $bankAccount = BankAccount::query()
            ->where('company_id', $companyId)
            ->whereKey($bankAccountId)
            ->first();

        if ($bankAccount === null) {
            throw TransferStateException::unknownAccount($side, $bankAccountId);
        }

        if (! $bankAccount->status->acceptsNewEntries()) {
            throw TransferStateException::accountNotOpen($side, $bankAccount->name, $bankAccount->status);
        }

        return $bankAccount;
    }
}
