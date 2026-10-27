<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Actions\CreateJournal;
use App\Domain\Accounting\Actions\PostJournal;
use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Enums\JournalType;
use App\Domain\Accounting\Enums\PartyType;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Domain\Banking\DTOs\PaymentData;
use App\Domain\Banking\Enums\PaymentStatus;
use App\Domain\Banking\Exceptions\PaymentStateException;
use App\Domain\Purchases\Enums\BillStatus;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records one movement of money and everything that follows from it.
 *
 * A receipt from a customer is posted as
 *
 *   Dr  the cash or bank account the money landed in   the amount
 *   Cr  the customer's receivable control account      the amount
 *
 * and a payment to a supplier is the same entry with the two sides swapped.
 * The allocations that follow do not touch the ledger again: they move value
 * from the control account onto individual documents, which is why each
 * allocation row points at the control line of the journal rather than at a
 * journal of its own.
 *
 * Idempotency is enforced by the database, not by a check in PHP: the unique
 * index on (company_id, idempotency_key) means a retried receipt loses the
 * insert race and the original is returned, so a customer can never be credited
 * twice by a client that retried a timeout.
 */
final class RecordPayment
{
    public function __construct(
        private readonly CreateJournal $create,
        private readonly PostJournal $post,
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumbering $numbering,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(PaymentData $data): Payment
    {
        return DB::transaction(function () use ($data): Payment {
            if ($data->idempotencyKey !== null) {
                $replay = Payment::query()
                    ->where('company_id', $data->companyId)
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

                if ($replay !== null) {
                    return $replay->load('allocations');
                }
            }

            $amount = Money::of($data->amount);

            if (! $amount->isPositive()) {
                throw PaymentStateException::nonPositiveAmount();
            }

            $expectedParty = $data->direction->isInflow() ? PartyType::Customer : PartyType::Supplier;

            if ($data->partyType !== $expectedParty) {
                throw PaymentStateException::wrongPartyForDirection($data->direction, $data->partyType);
            }

            $party = $this->loadParty($data);
            $controlAccountId = $data->partyType === PartyType::Customer
                ? (int) $party->receivable_account_id
                : (int) $party->payable_account_id;

            // The caller names which of the company's own cash or bank accounts
            // the money moved through; the ledger account is derived from that
            // row rather than trusted from the request.
            $bankAccount = $this->bankAccount($data);
            $settlementAccountId = (int) $bankAccount->account_id;

            $allocated = Money::sum(array_map(
                static fn (array $allocation): Money => Money::of($allocation['amount']),
                array_values($data->allocations)
            ));

            if ($allocated->greaterThan($amount)) {
                throw PaymentStateException::overAllocated($amount, $allocated);
            }

            $period = $this->calendar->resolve($data->companyId, $data->paymentDate);

            $payment = new Payment([
                'company_id' => $data->companyId,
                'payment_no' => $this->numbering->next(
                    $data->companyId,
                    DocumentType::Payment,
                    $period->fiscal_year_id
                ),
                'direction' => $data->direction->value,
                'party_type' => $data->partyType->value,
                'party_id' => $data->partyId,
                'bank_account_id' => $bankAccount->getKey(),
                'payment_date' => $data->paymentDate,
                'currency_code' => $data->currencyCode
                    ?? Company::query()->findOrFail($data->companyId)->base_currency_code,
                'exchange_rate' => $data->exchangeRate,
                'amount' => $amount->toDecimal(),
                'method' => $data->method->value,
                'reference' => $data->reference,
                'status' => PaymentStatus::Draft->value,
                'idempotency_key' => $data->idempotencyKey,
                'created_by_user_id' => $data->userId,
            ]);

            $payment->allocated_amount = $allocated->toDecimal();
            $payment->unapplied_amount = $amount->minus($allocated)->toDecimal();

            try {
                $payment->save();
            } catch (UniqueConstraintViolationException $exception) {
                // Another request carrying the same key got here first.
                $winner = $data->idempotencyKey === null ? null : Payment::query()
                    ->where('company_id', $data->companyId)
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

                if ($winner === null) {
                    throw $exception;
                }

                return $winner->load('allocations');
            }

            $memo = $data->direction->label().' '.$payment->payment_no;

            $journal = ($this->create)(new JournalEntryData(
                companyId: $data->companyId,
                postingDate: $data->paymentDate,
                journalType: $data->direction->isInflow() ? JournalType::Receipt : JournalType::Payment,
                lines: $this->journalLines($data, $amount, $controlAccountId, $settlementAccountId, $memo),
                reference: $payment->payment_no,
                memo: $memo,
                documentDate: $data->paymentDate,
                currencyCode: $payment->currency_code,
                exchangeRate: $data->exchangeRate,
                sourceType: Payment::class,
                sourceId: (int) $payment->getKey(),
                createdByUserId: $data->userId,
            ));

            $posted = ($this->post)($journal, $data->idempotencyKey, $data->userId, $journal->row_version);

            $controlLineId = $posted->lines()
                ->where('account_id', $controlAccountId)
                ->value('id');

            foreach (array_values($data->allocations) as $allocation) {
                $this->allocate($data, $payment, $party, $allocation, $controlLineId === null ? null : (int) $controlLineId);
            }

            $payment->forceFill([
                'status' => PaymentStatus::Posted->value,
                'journal_id' => $posted->getKey(),
                'posted_at' => now(),
                'row_version' => $payment->row_version + 1,
            ])->save();

            $this->audit->record('payment.posted', $payment, null, [
                'payment_no' => $payment->payment_no,
                'direction' => $payment->direction->value,
                'amount' => $payment->amount,
                'allocated_amount' => $payment->allocated_amount,
                'journal_no' => $posted->journal_no,
            ]);

            return $payment->load('allocations');
        });
    }

    /**
     * The company's own cash or bank account the money moved through.
     *
     * Resolved inside the tenant so an id from another company finds nothing,
     * and refused unless it is still open for business: a closed account keeps
     * its history but must not be handed new money, and an inactive one is
     * parked rather than in use.
     */
    private function bankAccount(PaymentData $data): BankAccount
    {
        $bankAccount = BankAccount::query()
            ->where('company_id', $data->companyId)
            ->whereKey($data->bankAccountId)
            ->first();

        if ($bankAccount === null) {
            throw PaymentStateException::unknownBankAccount($data->bankAccountId);
        }

        if (! $bankAccount->status->acceptsNewEntries()) {
            throw PaymentStateException::bankAccountNotOpen($bankAccount->name, $bankAccount->status);
        }

        return $bankAccount;
    }

    private function loadParty(PaymentData $data): Customer|Supplier
    {
        $query = $data->partyType === PartyType::Customer
            ? Customer::query()
            : Supplier::query();

        return $query
            ->where('company_id', $data->companyId)
            ->whereKey($data->partyId)
            ->firstOrFail();
    }

    /**
     * @return array<int, JournalLineData>
     */
    private function journalLines(
        PaymentData $data,
        Money $amount,
        int $controlAccountId,
        int $settlementAccountId,
        string $memo,
    ): array {
        $control = new JournalLineData(
            accountId: $controlAccountId,
            debit: $data->direction->isInflow() ? '0' : $amount,
            credit: $data->direction->isInflow() ? $amount : '0',
            description: $memo,
            partyType: $data->partyType,
            partyId: $data->partyId,
        );

        $settlement = new JournalLineData(
            accountId: $settlementAccountId,
            debit: $data->direction->isInflow() ? $amount : '0',
            credit: $data->direction->isInflow() ? '0' : $amount,
            description: $memo,
        );

        // A receipt debits the account the money landed in; a payment credits it.
        return $data->direction->isInflow()
            ? [$settlement, $control]
            : [$control, $settlement];
    }

    /**
     * @param  array{document_type: string, document_id: int, amount: string}  $allocation
     */
    private function allocate(
        PaymentData $data,
        Payment $payment,
        Customer|Supplier $party,
        array $allocation,
        ?int $journalLineId,
    ): void {
        $amount = Money::of($allocation['amount']);

        if (! $amount->isPositive()) {
            throw new PaymentStateException('An allocation must be for an amount greater than zero.');
        }

        $documentId = (int) $allocation['document_id'];
        $documentType = (string) $allocation['document_type'];

        match ($documentType) {
            'invoice' => $this->allocateToInvoice($data, $party, $documentId, $amount),
            'bill' => $this->allocateToBill($data, $party, $documentId, $amount),
            default => throw PaymentStateException::unknownDocument($documentType, $documentId),
        };

        PaymentAllocation::create([
            'company_id' => $data->companyId,
            'payment_id' => $payment->getKey(),
            'document_type' => $documentType,
            'document_id' => $documentId,
            'amount' => $amount->toDecimal(),
            'journal_line_id' => $journalLineId,
        ]);
    }

    private function allocateToInvoice(
        PaymentData $data,
        Customer|Supplier $party,
        int $invoiceId,
        Money $amount,
    ): void {
        $invoice = Invoice::query()
            ->where('company_id', $data->companyId)
            ->whereKey($invoiceId)
            ->first();

        if ($invoice === null) {
            throw PaymentStateException::unknownDocument('invoice', $invoiceId);
        }

        if ((int) $invoice->customer_id !== $data->partyId) {
            throw PaymentStateException::documentPartyMismatch($invoice->invoice_no, $party->name);
        }

        if (! $invoice->status->isOpen()) {
            throw PaymentStateException::documentNotOpen(
                'invoice',
                $invoice->invoice_no,
                $invoice->status->label()
            );
        }

        $outstanding = $invoice->outstanding();

        if ($amount->greaterThan($outstanding)) {
            throw PaymentStateException::exceedsOutstanding($invoice->invoice_no, $outstanding, $amount);
        }

        $paid = Money::of($invoice->amount_paid)->plus($amount);

        $invoice->amount_paid = $paid->toDecimal();
        $invoice->status = $paid->greaterThanOrEqualTo(Money::of($invoice->total))
            ? InvoiceStatus::Paid
            : InvoiceStatus::PartiallyPaid;
        $invoice->row_version = $invoice->row_version + 1;
        $invoice->save();
    }

    private function allocateToBill(
        PaymentData $data,
        Customer|Supplier $party,
        int $billId,
        Money $amount,
    ): void {
        $bill = Bill::query()
            ->where('company_id', $data->companyId)
            ->whereKey($billId)
            ->first();

        if ($bill === null) {
            throw PaymentStateException::unknownDocument('bill', $billId);
        }

        if ((int) $bill->supplier_id !== $data->partyId) {
            throw PaymentStateException::documentPartyMismatch($bill->bill_no, $party->name);
        }

        // An approved bill that has not been posted has no liability in the
        // ledger yet, so paying it would move money against nothing.
        if ($bill->journal_id === null || ! $bill->status->isOpen()) {
            throw PaymentStateException::documentNotOpen(
                'bill',
                $bill->bill_no,
                $bill->status->label()
            );
        }

        $outstanding = $bill->outstanding();

        if ($amount->greaterThan($outstanding)) {
            throw PaymentStateException::exceedsOutstanding($bill->bill_no, $outstanding, $amount);
        }

        $paid = Money::of($bill->amount_paid)->plus($amount);

        $bill->amount_paid = $paid->toDecimal();
        $bill->status = $paid->greaterThanOrEqualTo(Money::of($bill->total))
            ? BillStatus::Paid
            : BillStatus::PartiallyPaid;
        $bill->row_version = $bill->row_version + 1;
        $bill->save();
    }
}
