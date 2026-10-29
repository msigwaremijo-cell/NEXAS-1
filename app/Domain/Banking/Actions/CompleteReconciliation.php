<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounting\Services\AuditTrail;
use App\Domain\Banking\Exceptions\ReconciliationException;
use App\Domain\Banking\Exceptions\ReconciliationStateException;
use App\Domain\Banking\Services\ReconciliationSummary;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Reconciliation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Signs off the reconciliation of one account's statement.
 *
 * Completing is not a second round of matching, it is a statement about the
 * whole: that the figures agree, and that a person is prepared to put their
 * name to the agreement. So it is only allowed once the account has answered
 * every question the statement asked.
 *
 * Four things have to hold first, and the wording of each refusal names the
 * figure the reader has to go and deal with:
 *
 *   the statement adds up          its printed balances have to follow its own
 *                                  movements, because a file that does not is
 *                                  missing rows or repeating them, and signing
 *                                  off a figure the bank never printed is worse
 *                                  than not signing off at all
 *   every line is decided          no line left unmatched, and none only
 *                                  half matched — a half matched movement is
 *                                  invisible in the difference between the two
 *                                  balances, which is exactly why it is checked
 *   the difference is explained    the gap between the bank's closing balance
 *                                  and the ledger has to be accounted for by
 *                                  the lines that were deliberately ignored.
 *                                  Ignoring a line that IS in the books leaves
 *                                  a real difference behind rather than hiding
 *                                  one, and it is refused until that is fixed
 *   there is something to stamp    a reconciliation already signed off, with no
 *                                  later match waiting, is answered as such
 *
 * The stamp itself is one write: reconciled_at and reconciled_by_user_id
 * together, so the CHECK that pairs them can never be observed half satisfied,
 * and every match is stamped in the same statement so no match is left behind
 * as tentative. After it, the database freezes the stamped rows and the
 * statement lines they covered: a signed-off reconciliation is evidence, and a
 * later correction is an adjusting entry in the books rather than an edit to
 * the evidence.
 *
 * The signature is not optional. A completion stamp that names no one is a
 * claim nobody can be held to, so the caller has to say who is signing.
 */
final class CompleteReconciliation
{
    public function __construct(
        private readonly ReconciliationSummary $summary,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @return array<string, mixed> the reconciliation as it now stands
     */
    public function __invoke(int $companyId, int $bankAccountId, int $userId): array
    {
        return DB::transaction(function () use ($companyId, $bankAccountId, $userId): array {
            $account = BankAccount::query()
                ->where('company_id', $companyId)
                ->whereKey($bankAccountId)
                ->first();

            if ($account === null) {
                throw ReconciliationException::unknownBankAccount($bankAccountId);
            }

            $standing = $this->summary->for($companyId, $account);

            if ((int) $standing['counts']['statement_lines'] === 0) {
                throw ReconciliationStateException::nothingToComplete(null);
            }

            if (! $standing['statement']['has_running_balance']) {
                throw ReconciliationStateException::statementHasNoRunningBalance();
            }

            if (! $standing['statement']['internally_consistent']) {
                throw ReconciliationStateException::statementBalanceInconsistent(
                    Money::of((string) $standing['statement']['opening_balance']),
                    Money::of((string) $standing['statement']['movement']),
                    Money::of((string) $standing['statement']['closing_balance']),
                    Money::of((string) $standing['statement']['expected_closing_balance']),
                );
            }

            $difference = Money::of((string) $standing['difference']);
            $ignored = Money::of((string) $standing['statement']['ignored_movement']);
            $outstanding = (int) $standing['counts']['outstanding'];

            if ($outstanding > 0 || ! $difference->minus($ignored)->isZero()) {
                throw ReconciliationStateException::incomplete($outstanding, $difference);
            }

            // Nothing left waiting to be stamped: either the statement was
            // already signed off, or every line on it was ignored and there is
            // no match to put a name to.
            if ((int) $standing['counts']['tentative_matches'] === 0) {
                throw ReconciliationStateException::nothingToComplete($standing['completed_at']);
            }

            $stamp = now();

            $stamped = Reconciliation::query()
                ->whereIn('bank_statement_line_id', $this->statementLineIds($companyId, $bankAccountId))
                ->whereNull('reconciled_at')
                ->update([
                    'reconciled_at' => $stamp,
                    'reconciled_by_user_id' => $userId,
                ]);

            $this->audit->record('reconciliation.completed', $account, null, [
                'matches' => $stamped,
                'completed_at' => $stamp->toDateTimeString(),
            ]);

            return $this->summary->for($companyId, $account);
        });
    }

    /**
     * The statement lines of this account, which is what bounds the stamp: a
     * reconciliation covers one account's statement and never reaches into
     * another's.
     *
     * @return list<int>
     */
    private function statementLineIds(int $companyId, int $bankAccountId): array
    {
        return BankStatementLine::query()
            ->where('company_id', $companyId)
            ->where('bank_account_id', $bankAccountId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
