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
 * Undoes one match while it is still tentative.
 *
 * A match is a claim that has not been signed off, and a person leaning over the
 * screen is allowed to change their mind: the wrong journal line, an amount
 * typed a digit out. Releasing removes the tie and puts the statement line back
 * to unmatched when nothing else covers it, so the line returns to the list of
 * movements still waiting.
 *
 * A match covered by a completed reconciliation cannot be released. That is the
 * whole point of completing one: what was signed off stays as it was, and a
 * later correction is an adjusting entry in the books rather than an edit to
 * the evidence. The database refuses this too; asking here is what turns the
 * refusal into one that says which reconciliation closed the line.
 */
final class ReleaseMatch
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function __invoke(int $companyId, int $bankAccountId, int $reconciliationId): BankStatementLine
    {
        return DB::transaction(function () use ($companyId, $bankAccountId, $reconciliationId): BankStatementLine {
            $match = Reconciliation::query()
                ->where('company_id', $companyId)
                ->whereKey($reconciliationId)
                ->first();

            if ($match === null) {
                throw ReconciliationException::unknownReconciliation($reconciliationId);
            }

            // Scoped to the account the route names as well as the company, so a
            // match belonging to another account of the same company is not
            // reachable through this account's reconciliation.
            $line = BankStatementLine::query()
                ->where('company_id', $companyId)
                ->where('bank_account_id', $bankAccountId)
                ->whereKey($match->bank_statement_line_id)
                ->first();

            if ($line === null) {
                throw ReconciliationException::unknownReconciliation($reconciliationId);
            }

            if ($match->isCompleted()) {
                throw ReconciliationStateException::statementLineIsLocked();
            }

            $removed = [
                'journal_line_id' => (int) $match->journal_line_id,
                'matched_amount' => (string) $match->matched_amount,
            ];

            $match->delete();

            $stillCovered = Reconciliation::query()
                ->where('bank_statement_line_id', (int) $line->getKey())
                ->exists();

            if (! $stillCovered && $line->status === BankStatementLineStatus::Matched) {
                $line->status = BankStatementLineStatus::Unmatched;
                $line->save();
            }

            $this->audit->record('reconciliation.released', $line, $removed, null);

            return $line->refresh()->load('reconciliations');
        });
    }
}
