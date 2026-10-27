<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\DTOs\JournalEntryData;
use App\Domain\Accounting\DTOs\JournalLineData;
use App\Domain\Accounting\Enums\DocumentType;
use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Exceptions\InvalidJournalException;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\FiscalCalendar;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft journal and its lines in one transaction.
 *
 * Everything the posting step is forbidden to change is settled here, because
 * trg_journals_before_update rejects any change to company_id, fiscal_year_id,
 * fiscal_period_id, journal_no, journal_type, posting_date, currency_code,
 * exchange_rate or reverses_journal_id during the draft -> posted update:
 *
 *   - the fiscal year and period are resolved from the posting date;
 *   - the journal number is taken from the locked sequence for that year;
 *   - the reversal link, if any, is already final.
 *
 * The draft is deliberately allowed to be unbalanced. Balance is a posting
 * rule, and a half-finished entry is a legitimate draft.
 */
final class CreateJournal
{
    public function __construct(
        private readonly FiscalCalendar $calendar,
        private readonly DocumentNumbering $numbering,
        private readonly AuditTrail $audit,
    ) {}

    public function __invoke(JournalEntryData $data): Journal
    {
        return DB::transaction(function () use ($data): Journal {
            $company = Company::query()->findOrFail($data->companyId);

            $period = $this->calendar->resolve($data->companyId, $data->postingDate);

            $this->assertAccountsAcceptPostings($data);

            $currencyCode = $data->currencyCode ?? $company->base_currency_code;

            $journal = new Journal([
                'company_id' => $data->companyId,
                'fiscal_year_id' => $period->fiscal_year_id,
                'fiscal_period_id' => $period->getKey(),
                'journal_no' => $this->numbering->next(
                    $data->companyId,
                    DocumentType::Journal,
                    $period->fiscal_year_id
                ),
                'journal_type' => $data->journalType->value,
                'status' => JournalStatus::Draft->value,
                'posting_date' => $data->postingDate,
                'document_date' => $data->documentDate ?? $data->postingDate,
                'reference' => $data->reference,
                'memo' => $data->memo,
                'currency_code' => $currencyCode,
                'exchange_rate' => $data->exchangeRate,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'reverses_journal_id' => $data->reversesJournalId,
                'created_by_user_id' => $data->createdByUserId,
            ]);

            $journal->save();

            $lineNo = 1;

            foreach ($data->lines as $line) {
                $rate = $line->exchangeRate ?? $data->exchangeRate;

                JournalLine::create([
                    'company_id' => $data->companyId,
                    'journal_id' => $journal->getKey(),
                    'line_no' => $lineNo,
                    'account_id' => $line->accountId,
                    'description' => $line->description,
                    'debit' => $line->debit->toDecimal(),
                    'credit' => $line->credit->toDecimal(),
                    'currency_code' => $line->currencyCode ?? $currencyCode,
                    'exchange_rate' => $rate,
                    'base_debit' => $line->debit->times($rate)->toDecimal(),
                    'base_credit' => $line->credit->times($rate)->toDecimal(),
                    'posting_date' => $data->postingDate,
                    'tax_code_id' => $line->taxCodeId,
                    'tax_amount' => $line->taxAmount,
                    'party_type' => $line->partyType?->value,
                    'party_id' => $line->partyId,
                    'branch_id' => $line->branchId,
                ]);

                $lineNo++;
            }

            $journal->setRelation('lines', JournalLine::query()
                ->where('journal_id', $journal->getKey())
                ->orderBy('line_no')
                ->get());

            $this->audit->record('journal.created', $journal, null, [
                'journal_no' => $journal->journal_no,
                'status' => JournalStatus::Draft->value,
                'line_count' => count($data->lines),
            ]);

            return $journal;
        });
    }

    /**
     * A header account rolls its children up; it must never carry a posting
     * itself. Soft-deleted accounts are already excluded by the model scope.
     */
    private function assertAccountsAcceptPostings(JournalEntryData $data): void
    {
        $accountIds = array_values(array_unique(array_map(
            static fn (JournalLineData $line): int => $line->accountId,
            array_values($data->lines)
        )));

        $accounts = Account::query()
            ->where('company_id', $data->companyId)
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        foreach ($accountIds as $accountId) {
            $account = $accounts->get($accountId);

            if ($account === null) {
                throw InvalidJournalException::unknownAccount($accountId);
            }

            if (! $account->is_postable) {
                throw InvalidJournalException::notPostable($account->code);
            }
        }
    }
}
