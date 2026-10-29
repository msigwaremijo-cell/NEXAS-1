<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Banking\Enums\BankStatementLineStatus;
use App\Domain\Banking\Exceptions\ReconciliationException;
use App\Domain\Banking\Exceptions\ReconciliationStateException;
use App\Models\BankStatementLine;
use App\Models\Reconciliation;
use Illuminate\Support\Facades\DB;

/**
 * Takes one movement off a statement out of the reconciliation, on the record.
 *
 * Ignoring is the deliberate exception rather than a shortcut. It is what a
 * reconciler reaches for when a line is genuinely not part of these books — a
 * movement belonging to another account that the bank printed on the same
 * statement, a reversal pair that cancels itself — and the reason is written
 * down rather than remembered. An exception with no justification cannot be
 * told apart from an oversight, which is why the column and the CHECK behind it
 * both insist on one.
 *
 * An ignored line is not matched to anything and does not have to be: its
 * movement stays in the bank's balance but never enters the ledger, so it is
 * exactly the part of the difference between the two balances that a completed
 * reconciliation is allowed to leave standing. Ignoring a line that IS in the
 * books therefore leaves a real difference behind rather than hiding one, and
 * the completion is refused until that is put right.
 */
final class IgnoreStatementLine
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(
        int $companyId,
        int $bankAccountId,
        int $statementLineId,
        string $reason,
    ): BankStatementLine {
        return DB::transaction(function () use ($companyId, $bankAccountId, $statementLineId, $reason): BankStatementLine {
            $reason = trim($reason);

            if ($reason === '') {
                throw ReconciliationException::ignoredReasonRequired();
            }

            $line = BankStatementLine::query()
                ->where('company_id', $companyId)
                ->where('bank_account_id', $bankAccountId)
                ->whereKey($statementLineId)
                ->first();

            if ($line === null) {
                throw ReconciliationException::unknownStatementLine($statementLineId);
            }

            if (Reconciliation::statementLineIsLocked((int) $line->getKey())) {
                throw ReconciliationStateException::statementLineIsLocked();
            }

            if ($line->status === BankStatementLineStatus::Ignored) {
                throw ReconciliationException::lineAlreadyIgnored();
            }

            // Ignoring and matching are two answers to the same question, so a
            // line cannot carry both: the movement would be explained twice or
            // not at all depending on which reader looked.
            if (Reconciliation::query()->where('bank_statement_line_id', (int) $line->getKey())->exists()) {
                throw ReconciliationException::lineHasMatches();
            }

            $line->status = BankStatementLineStatus::Ignored;
            $line->ignored_reason = $reason;
            $line->save();

            $this->audit->record('reconciliation.ignored', $line, null, [
                'reason' => $reason,
            ]);

            return $line->refresh();
        });
    }
}
