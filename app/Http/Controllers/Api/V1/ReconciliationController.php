<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\AutoMatchStatementLines;
use App\Domain\Banking\Actions\CompleteReconciliation;
use App\Domain\Banking\Actions\IgnoreStatementLine;
use App\Domain\Banking\Actions\MatchStatementLine;
use App\Domain\Banking\Actions\ReleaseMatch;
use App\Domain\Banking\DTOs\MatchAllocationData;
use App\Domain\Banking\DTOs\MatchData;
use App\Domain\Banking\Services\ReconciliationSummary;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\Reconciliation;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Comparing one account's statement with the books, and the record of having
 * done so.
 *
 * The accounting lives in the Banking actions: this surface validates the shape
 * of what was sent, asks the policy whether the caller may decide, and hands
 * back what the actions produced. Nothing here filters a journal line, checks a
 * side or adds up a balance — a rule kept in two places is a rule that will
 * disagree with itself.
 *
 * Reading is one right and deciding is another. The summary is a report, so it
 * sits behind the same policy as the account itself and the Auditor keeps it.
 * Matching, ignoring, releasing and completing all sit behind `reconcile`,
 * because each one changes the record of what somebody agreed to.
 *
 * Nothing on this surface contacts a bank and nothing posts to the ledger. A
 * statement is a file somebody downloaded from their online banking, and a
 * match only records which ledger entry a movement already in the books is.
 */
class ReconciliationController extends Controller
{
    /**
     * Where the account stands: what the statement says, what the books say,
     * the distance between them, and what is still undecided.
     */
    public function show(
        Request $request,
        string $bankAccount,
        ReconciliationSummary $summary,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('view', $account);

        return response()->json(['data' => $this->presentReconciliation($summary, $account)]);
    }

    /**
     * Record that one movement on the statement is these ledger entries.
     *
     * The allocations carry an amount each, which is what expresses a split: a
     * single movement the bank printed once may be several entries in the books,
     * and each entry is named with how much of the movement it accounts for.
     */
    public function match(
        Request $request,
        string $bankAccount,
        MatchStatementLine $match,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('reconcile', $account);

        $companyId = (int) $account->company_id;

        $data = $request->validate([
            'statement_line_id' => ['required', 'integer', 'min:1'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.journal_line_id' => ['required', 'integer', 'min:1'],
            // A decimal string, never a JSON number with a fraction: a float
            // cannot round-trip DECIMAL(19,4). "0" is accepted here and refused
            // by the action, which is where the rule about amounts lives.
            'allocations.*.amount' => ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+)?$/'],
        ]);

        $line = $match(new MatchData(
            companyId: $companyId,
            bankAccountId: (int) $account->getKey(),
            statementLineId: (int) $data['statement_line_id'],
            allocations: array_map(
                static fn (array $allocation): MatchAllocationData => new MatchAllocationData(
                    journalLineId: (int) $allocation['journal_line_id'],
                    amount: (string) $allocation['amount'],
                ),
                $data['allocations'],
            ),
            userId: $request->user()?->getKey(),
        ));

        return response()->json(['data' => $this->presentLine($line)], 201);
    }

    /**
     * Let the matcher place the obvious ones.
     *
     * A convenience over the same door a hand match goes through: the caller
     * gets back what was placed, what the matcher would not decide, and what it
     * found nothing for, so the screen can say which movements still need a
     * person rather than quietly leaving them.
     */
    public function autoMatch(
        Request $request,
        string $bankAccount,
        AutoMatchStatementLines $autoMatch,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('reconcile', $account);

        $data = $request->validate([
            'date_window_days' => ['sometimes', 'integer', 'min:0', 'max:30'],
        ]);

        $result = $autoMatch(
            (int) $account->company_id,
            (int) $account->getKey(),
            $data['date_window_days'] ?? null,
            $request->user()?->getKey(),
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Undo one tentative match, while it is still tentative.
     */
    public function release(
        Request $request,
        string $bankAccount,
        int $reconciliation,
        ReleaseMatch $release,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('reconcile', $account);

        $line = $release((int) $account->company_id, (int) $account->getKey(), $reconciliation);

        return response()->json(['data' => $this->presentLine($line)]);
    }

    /**
     * Take one movement out of the reconciliation, with the reason written
     * down.
     */
    public function ignore(
        Request $request,
        string $bankAccount,
        IgnoreStatementLine $ignore,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('reconcile', $account);

        $data = $request->validate([
            'statement_line_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $line = $ignore(
            (int) $account->company_id,
            (int) $account->getKey(),
            (int) $data['statement_line_id'],
            (string) $data['reason'],
        );

        return response()->json(['data' => $this->presentLine($line)]);
    }

    /**
     * Sign the reconciliation off, which stamps every match and freezes what it
     * covered. The caller is the signature, so the action is told who is
     * signing.
     */
    public function complete(
        Request $request,
        string $bankAccount,
        CompleteReconciliation $complete,
    ): JsonResponse {
        $account = $this->resolveAccount($request, $bankAccount);

        $this->authorize('reconcile', $account);

        $standing = $complete(
            (int) $account->company_id,
            (int) $account->getKey(),
            (int) $request->user()->getKey(),
        );

        return response()->json(['data' => $standing]);
    }

    /**
     * Resolved inside the caller's company in the same query that reads it, so
     * naming another tenant's account is a 404 rather than a 403 that would
     * confirm the account exists somewhere else.
     */
    private function resolveAccount(Request $request, string $bankAccount): BankAccount
    {
        $companyId = $request->attributes->get('company_id');

        abort_if($companyId === null, 403, 'You are not a member of any company yet.');

        return BankAccount::query()
            ->where('company_id', $companyId)
            ->where('ulid', $bankAccount)
            ->firstOrFail();
    }

    /**
     * The figures and the lines together, because a reader asking where the
     * reconciliation stands is about to ask which movements are still open, and
     * the two questions are answered from the same read.
     *
     * @return array<string, mixed>
     */
    private function presentReconciliation(ReconciliationSummary $summary, BankAccount $account): array
    {
        $companyId = (int) $account->company_id;

        $lines = $summary->lines($companyId, $account);
        $matches = $summary->matches($companyId, $lines)->groupBy('bank_statement_line_id');

        return [
            'summary' => $summary->for($companyId, $account),
            'lines' => $lines
                ->map(fn (BankStatementLine $line): array => $this->presentLine(
                    $line,
                    $matches->get((int) $line->getKey(), collect()),
                ))
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Reconciliation>  $matches
     * @return array<string, mixed>
     */
    private function presentLine(BankStatementLine $line, ?Collection $matches = null): array
    {
        $matches ??= $line->relationLoaded('reconciliations')
            ? $line->reconciliations
            : $line->reconciliations()->get();

        return [
            'id' => (int) $line->getKey(),
            'ulid' => $line->ulid,
            'bank_account_id' => (int) $line->bank_account_id,
            'value_date' => $line->value_date->toDateString(),
            'description' => $line->description,
            'reference' => $line->reference,
            // The bank's own sides, not the books': debit is money that left the
            // account and credit is money that arrived in it.
            'debit' => $line->debit,
            'credit' => $line->credit,
            'balance_after' => $line->balance_after,
            'status' => $line->status->value,
            'ignored_reason' => $line->ignored_reason,
            'matched_amount' => Money::sum(
                $matches->map(static fn (Reconciliation $match): Money => Money::of((string) $match->matched_amount))->all()
            )->toDecimal(),
            'matches' => $matches
                ->map(static fn (Reconciliation $match): array => [
                    'id' => (int) $match->getKey(),
                    'journal_line_id' => (int) $match->journal_line_id,
                    'matched_amount' => $match->matched_amount,
                    'match_type' => $match->match_type->value,
                    'reconciled_at' => $match->reconciled_at?->toDateTimeString(),
                    'reconciled_by_user_id' => $match->reconciled_by_user_id === null
                        ? null
                        : (int) $match->reconciled_by_user_id,
                ])
                ->values()
                ->all(),
        ];
    }
}
