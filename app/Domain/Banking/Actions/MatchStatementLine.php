<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Enums\JournalStatus;
use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Banking\DTOs\MatchAllocationData;
use App\Domain\Banking\DTOs\MatchData;
use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Banking\Exceptions\ReconciliationException;
use App\Domain\Banking\Exceptions\ReconciliationStateException;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\Reconciliation;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ties one movement on a statement to the entry the company made for it.
 *
 * This is the single door every match goes through. The automatic matcher does
 * not write anything of its own: it proposes pairs, and each proposal is
 * applied here, so a match the machine made and a match a person made are the
 * same row carrying the same guarantees. Nothing on this path posts to the
 * ledger and nothing changes a balance — the books already hold every movement
 * a match can find, and matching only records which one the bank's line is.
 *
 * Four things are checked before a single row is written:
 *
 *   the ledger entry belongs to this account   a statement belongs to one
 *                                              account, so a movement on any
 *                                              other account belongs to that
 *                                              account's own reconciliation
 *   the journal has reached the ledger         a draft's lines are physically
 *                                              in journal_lines from the moment
 *                                              the draft is written, and a
 *                                              statement cannot be reconciled
 *                                              against something not posted
 *   the sides agree                            the bank and the company write
 *                                              the same movement on opposite
 *                                              sides, so money arriving is the
 *                                              bank's credit and the company's
 *                                              debit. The side is flipped; the
 *                                              amount never is
 *   nobody runs out of room                    neither the statement line nor
 *                                              the ledger entry may be covered
 *                                              for more than it moved, because
 *                                              an entry spent twice is how two
 *                                              movements come to be explained by
 *                                              one
 *
 * A split match is the ordinary case these rules exist to allow: one movement
 * on the statement, several entries in the books, each with its own amount. The
 * sum of the allocations is what the statement line is covered for, and each
 * allocation is measured against the entry it names.
 *
 * A statement line locked by a completed reconciliation is refused with a 409
 * rather than a 422, and the database refuses it too: a signed-off figure is
 * evidence, and what it matched cannot be changed afterwards.
 */
final class MatchStatementLine
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(MatchData $data): BankStatementLine
    {
        return DB::transaction(function () use ($data): BankStatementLine {
            if ($data->allocations === []) {
                throw ReconciliationException::noAllocations();
            }

            $account = $this->resolveBankAccount($data->companyId, $data->bankAccountId);
            $line = $this->resolveStatementLine($data);

            $this->assertNotLocked($line);
            $this->assertNotIgnored($line);

            $journalLines = $this->resolveJournalLines($data, $account, $line);

            $matched = $data->matchedTotal();

            $this->assertStatementLineHasRoom($line, $matched);
            $this->assertJournalLinesHaveRoom($data, $journalLines);

            foreach ($data->allocations as $allocation) {
                Reconciliation::create([
                    'company_id' => $data->companyId,
                    'bank_statement_line_id' => (int) $line->getKey(),
                    'journal_line_id' => $allocation->journalLineId,
                    'matched_amount' => $allocation->amount,
                    'match_type' => $data->matchType->value,
                ]);
            }

            // The status moves at match time, while the match is still
            // tentative, rather than at completion. A completed reconciliation
            // freezes the statement lines it covered, so nothing may be written
            // to one after the lock closes and the stamp has to be the last
            // write of all.
            if ($line->status !== BankStatementLineStatus::Matched) {
                $line->status = BankStatementLineStatus::Matched;
                $line->save();
            }

            $this->audit->record('reconciliation.matched', $line, null, [
                'match_type' => $data->matchType->value,
                'matched_amount' => $matched->toDecimal(),
                'journal_line_ids' => array_map(
                    static fn (MatchAllocationData $allocation): int => $allocation->journalLineId,
                    $data->allocations
                ),
            ]);

            return $line->refresh()->load('reconciliations');
        });
    }

    /**
     * The account the statement belongs to, resolved inside the tenant in the
     * same query that reads it, so an id from another company's books finds
     * nothing rather than finding somebody else's account.
     */
    private function resolveBankAccount(int $companyId, int $bankAccountId): BankAccount
    {
        $bankAccount = BankAccount::query()
            ->where('company_id', $companyId)
            ->whereKey($bankAccountId)
            ->with('ledgerAccount')
            ->first();

        if ($bankAccount === null) {
            throw ReconciliationException::unknownBankAccount($bankAccountId);
        }

        return $bankAccount;
    }

    /**
     * The statement line, scoped to this account as well as this company: a
     * line of a different account of the same company is not this statement's
     * line, whichever id was sent.
     */
    private function resolveStatementLine(MatchData $data): BankStatementLine
    {
        $line = BankStatementLine::query()
            ->where('company_id', $data->companyId)
            ->where('bank_account_id', $data->bankAccountId)
            ->whereKey($data->statementLineId)
            ->first();

        if ($line === null) {
            throw ReconciliationException::unknownStatementLine($data->statementLineId);
        }

        return $line;
    }

    private function assertNotLocked(BankStatementLine $line): void
    {
        if (Reconciliation::statementLineIsLocked((int) $line->getKey())) {
            throw ReconciliationStateException::statementLineIsLocked();
        }
    }

    private function assertNotIgnored(BankStatementLine $line): void
    {
        if ($line->status === BankStatementLineStatus::Ignored) {
            throw ReconciliationException::lineAlreadyIgnored();
        }
    }

    /**
     * Every ledger entry this match names, checked against the account and
     * returned keyed by id so the room checks below do not read them again.
     *
     * The allocations are validated in the order a reader would: that the same
     * entry is not named twice, that each amount is a movement rather than
     * nothing, and only then what the entry actually is.
     *
     * @return Collection<int, JournalLine>
     */
    private function resolveJournalLines(MatchData $data, BankAccount $account, BankStatementLine $line): Collection
    {
        $ids = [];

        foreach ($data->allocations as $allocation) {
            if (isset($ids[$allocation->journalLineId])) {
                throw ReconciliationException::duplicateAllocation($allocation->journalLineId);
            }

            $ids[$allocation->journalLineId] = true;

            if (! Money::of($allocation->amount)->isPositive()) {
                throw ReconciliationException::amountNotPositive($allocation->journalLineId);
            }
        }

        $journalLines = JournalLine::query()
            ->where('company_id', $data->companyId)
            ->whereIn('id', array_keys($ids))
            ->with('journal:id,status')
            ->get()
            ->keyBy(static fn (JournalLine $journalLine): int => (int) $journalLine->getKey());

        $moneyIn = $this->moneyIn($line);

        foreach ($data->allocations as $allocation) {
            $journalLine = $journalLines->get($allocation->journalLineId);

            if ($journalLine === null) {
                throw ReconciliationException::unknownJournalLine($allocation->journalLineId);
            }

            if ((int) $journalLine->account_id !== (int) $account->account_id) {
                throw ReconciliationException::journalLineOutsideTheAccount(
                    $allocation->journalLineId,
                    (string) $account->ledgerAccount?->code,
                );
            }

            if (! $this->isInTheLedger($journalLine)) {
                throw ReconciliationException::journalLineNotInTheLedger($allocation->journalLineId);
            }

            if ($journalLine->isDebit() !== $moneyIn) {
                throw ReconciliationException::sideMismatch($allocation->journalLineId);
            }
        }

        return $journalLines;
    }

    /**
     * A draft's lines sit in journal_lines from the moment the draft is
     * written, so being in the table is not the same as being in the ledger.
     * Only a posted journal — and a reversed one, whose lines stay in the ledger
     * and are cancelled by the journal that reversed them — counts.
     */
    private function isInTheLedger(JournalLine $journalLine): bool
    {
        $status = $journalLine->journal?->status;

        return $status !== null
            && in_array($status->value, JournalStatus::ledgerStatuses(), true);
    }

    /**
     * The statement line may not be covered for more than it moved. This is
     * what makes a second, overlapping match on the same movement a refusal
     * rather than a line explained twice.
     */
    private function assertStatementLineHasRoom(BankStatementLine $line, Money $adding): void
    {
        $capacity = $this->capacity($line);

        $alreadyMatched = Money::sum(
            Reconciliation::query()
                ->where('bank_statement_line_id', (int) $line->getKey())
                ->pluck('matched_amount')
                ->map(static fn (mixed $amount): Money => Money::of((string) $amount))
                ->all()
        );

        if ($alreadyMatched->plus($adding)->greaterThan($capacity)) {
            throw ReconciliationException::overMatchedStatementLine($capacity, $alreadyMatched, $adding);
        }
    }

    /**
     * And the same rule from the other side: a ledger entry that is already
     * spoken for cannot be spent a second time.
     *
     * @param  Collection<int, JournalLine>  $journalLines
     */
    private function assertJournalLinesHaveRoom(MatchData $data, Collection $journalLines): void
    {
        $alreadyMatched = $this->matchedAmountsByJournalLine(array_map(
            static fn (MatchAllocationData $allocation): int => $allocation->journalLineId,
            $data->allocations
        ));

        foreach ($data->allocations as $allocation) {
            $journalLine = $journalLines->get($allocation->journalLineId);
            $used = $alreadyMatched[$allocation->journalLineId] ?? Money::zero();

            if ($used->plus(Money::of($allocation->amount))->greaterThan($journalLine->amount())) {
                throw ReconciliationException::overMatchedJournalLine($journalLine->amount(), $used);
            }
        }
    }

    /**
     * How much of each named entry another statement line has already claimed.
     * A completed match counts exactly as much as a tentative one: the entry is
     * spoken for either way.
     *
     * @param  list<int>  $journalLineIds
     * @return array<int, Money>
     */
    private function matchedAmountsByJournalLine(array $journalLineIds): array
    {
        $used = [];

        $matches = Reconciliation::query()
            ->whereIn('journal_line_id', $journalLineIds)
            ->get(['id', 'journal_line_id', 'matched_amount']);

        foreach ($matches as $match) {
            $journalLineId = (int) $match->journal_line_id;

            $used[$journalLineId] = ($used[$journalLineId] ?? Money::zero())
                ->plus(Money::of((string) $match->matched_amount));
        }

        return $used;
    }

    /**
     * The movement's magnitude, whichever side of the bank's columns it sits
     * on. Import guarantees exactly one side is filled, so this is never zero.
     */
    private function capacity(BankStatementLine $line): Money
    {
        return Money::of((string) $line->credit)
            ->minus(Money::of((string) $line->debit))
            ->absolute();
    }

    /**
     * Whether the bank printed this as money arriving in the account.
     */
    private function moneyIn(BankStatementLine $line): bool
    {
        return Money::of((string) $line->credit)->isPositive();
    }
}
