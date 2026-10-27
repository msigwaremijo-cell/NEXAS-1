<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Banking\Enums\BankAccountStatus;
use App\Domain\Banking\Enums\BankAccountType;
use App\Domain\Banking\Exceptions\BankAccountException;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Opens a cash, bank or mobile money account and brings any opening balance into
 * the books as a posted journal.
 *
 * The opening balance is not a number written on the account row and trusted: it
 * is a real double-entry posting, Dr the account's own ledger account against a
 * contra account the caller names. That is what makes the account's balance a
 * ledger balance from its first day, so a cash box cannot start life agreeing
 * with nothing.
 *
 * The journal goes through CreateJournal and PostJournal like every other
 * posting, so an opening entry obeys the same period lock, balance check and
 * trigger as a month-end journal. When a retry matters the caller passes an
 * idempotency key and it reaches PostJournal unchanged.
 */
final class OpenBankAccount
{
    public function __construct(
        private readonly CreateJournal $createJournal,
        private readonly PostJournal $postJournal,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  account_id, name, account_type,
     *                                            and optionally bank_name, account_number,
     *                                            branch_id, currency_code, opening_balance,
     *                                            opening_balance_account_id, opening_date
     */
    public function __invoke(
        int $companyId,
        array $attributes,
        ?int $userId = null,
        ?string $idempotencyKey = null,
    ): BankAccount {
        return DB::transaction(function () use ($companyId, $attributes, $userId, $idempotencyKey): BankAccount {
            // Scoped to the company in the same query that resolves it: linking
            // a cash box to another tenant's ledger account would post every
            // later receipt into the wrong set of books.
            $ledgerAccount = Account::query()
                ->where('company_id', $companyId)
                ->whereKey($attributes['account_id'])
                ->firstOrFail();

            if (! $ledgerAccount->is_postable) {
                throw BankAccountException::headerAccount($ledgerAccount);
            }

            if (! $ledgerAccount->is_cash && ! $ledgerAccount->is_bank) {
                throw BankAccountException::accountTypeNotCash($ledgerAccount);
            }

            $opening = Money::of($attributes['opening_balance'] ?? '0');

            $bankAccount = new BankAccount([
                'company_id' => $companyId,
                'branch_id' => $attributes['branch_id'] ?? null,
                'account_id' => $ledgerAccount->getKey(),
                'name' => $attributes['name'],
                'bank_name' => $attributes['bank_name'] ?? null,
                'account_number' => $attributes['account_number'] ?? null,
                'currency_code' => $attributes['currency_code'] ?? $ledgerAccount->currency_code ?? $this->baseCurrency($companyId),
                'account_type' => $attributes['account_type'] ?? BankAccountType::Bank->value,
                'status' => BankAccountStatus::Active->value,
            ]);

            $bankAccount->opening_balance = $opening->toDecimal();
            $bankAccount->save();

            if (! $opening->isZero()) {
                $this->postOpeningBalance($companyId, $bankAccount, $ledgerAccount, $opening, $attributes, $userId, $idempotencyKey);
            }

            $this->audit->record('bank_account.opened', $bankAccount, null, [
                'name' => $bankAccount->name,
                'account_id' => (int) $bankAccount->account_id,
                'opening_balance' => $bankAccount->opening_balance,
            ]);

            return $bankAccount;
        });
    }

    /**
     * Dr the account's ledger account and Cr the contra account for a positive
     * opening balance.
     *
     * A negative balance is an overdraft, which is a real thing for a bank
     * account, so the two sides are simply swapped rather than the figure being
     * rejected: the journal still balances and the account still opens owing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function postOpeningBalance(
        int $companyId,
        BankAccount $bankAccount,
        Account $ledgerAccount,
        Money $opening,
        array $attributes,
        ?int $userId,
        ?string $idempotencyKey,
    ): void {
        $openingDate = $attributes['opening_date'] ?? null;

        if ($openingDate === null) {
            throw BankAccountException::openingDateMissing();
        }

        $contraAccount = Account::query()
            ->where('company_id', $companyId)
            ->whereKey($attributes['opening_balance_account_id'] ?? 0)
            ->firstOrFail();

        $amount = $opening->absolute();
        $description = 'Opening balance: '.$bankAccount->name;

        $journal = ($this->createJournal)(new JournalEntryData(
            companyId: $companyId,
            postingDate: $openingDate,
            journalType: JournalType::Opening,
            lines: $opening->isNegative()
                ? [
                    JournalLineData::debit($contraAccount->getKey(), $amount, $description),
                    JournalLineData::credit($ledgerAccount->getKey(), $amount, $description),
                ]
                : [
                    JournalLineData::debit($ledgerAccount->getKey(), $amount, $description),
                    JournalLineData::credit($contraAccount->getKey(), $amount, $description),
                ],
            reference: $bankAccount->name,
            memo: $description,
            documentDate: $openingDate,
            sourceType: BankAccount::class,
            sourceId: (int) $bankAccount->getKey(),
            createdByUserId: $userId,
        ));

        ($this->postJournal)($journal, $idempotencyKey, $userId);
    }

    private function baseCurrency(int $companyId): string
    {
        return (string) Company::query()->findOrFail($companyId)->base_currency_code;
    }
}
