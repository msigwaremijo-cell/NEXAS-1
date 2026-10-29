<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Enums\AccountType;
use App\Domain\Accounting\Enums\JournalType;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Profit and loss over a period, and the balance sheet as at a date.
 *
 * Both read the ledger through LedgerQuery rather than through queries of their
 * own, so the rule that decides which journals are in the ledger — posted and
 * reversed, drafts left out — is stated once. A statement that restated it
 * would drift the first time a status was added, and it would drift silently:
 * the report would still add up, it would just be adding up a different set of
 * lines from the trial balance beside it.
 *
 * The accounts are read withTrashed on purpose. A statement has to reconcile
 * against the ledger, and the ledger still holds the lines of an account that
 * has since been taken out of the chart listing. Leaving such an account out
 * would not make the balance smaller, it would stop the balance sheet
 * balancing, which is the one thing a balance sheet may never do.
 *
 * Amounts are presented on the side the account's type expects: revenue,
 * liabilities and equity credit-positive; assets and expenses debit-positive.
 * A contra account therefore shows as a negative figure inside its own section,
 * which is what it is — sales discounts reduce revenue rather than being a
 * small expense on the other side, and the chart already says so by giving the
 * account the opposite normal balance.
 */
final class FinancialStatementQuery
{
    public function __construct(private readonly LedgerQuery $ledger) {}

    /**
     * Revenue less expense over a period.
     *
     * Closing entries are left out. See movementBetween() for why: they carry
     * the closed year's own last day as their posting date, so they fall inside
     * the window this report covers and would take a finished year's revenue and
     * expense back to nothing.
     *
     * Omitting both dates reports the whole ledger.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(int $companyId, ?string $from = null, ?string $to = null): array
    {
        $accounts = $this->accountsOfType($companyId, [AccountType::Revenue, AccountType::Expense]);

        $movements = $this->ledger->movementBetween(
            $companyId,
            $this->ids($accounts),
            $from,
            $to,
            [JournalType::Closing->value],
        );

        $revenue = [];
        $expense = [];

        foreach ($accounts as $account) {
            $net = $movements[(int) $account->getKey()] ?? Money::zero();

            // An account that never moved, or whose movements cancelled out
            // inside the window, has nothing to say on the statement.
            if ($net->isZero()) {
                continue;
            }

            $row = [
                'account_id' => (int) $account->getKey(),
                'account_ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                // The ledger's figure is debit minus credit; revenue reads the
                // other way round, so its own side is the positive one.
                'amount' => $account->account_type === AccountType::Revenue
                    ? $net->negated()->toDecimal()
                    : $net->toDecimal(),
            ];

            if ($account->account_type === AccountType::Revenue) {
                $revenue[] = $row;
            } else {
                $expense[] = $row;
            }
        }

        $totalRevenue = $this->total($revenue);
        $totalExpense = $this->total($expense);

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => ['accounts' => $revenue, 'total' => $totalRevenue->toDecimal()],
            'expense' => ['accounts' => $expense, 'total' => $totalExpense->toDecimal()],
            // Signed: a negative figure is a loss, not a negative profit to be
            // interpreted by the reader's own convention.
            'net_profit' => $totalRevenue->minus($totalExpense)->toDecimal(),
        ];
    }

    /**
     * What the company owns and what it owes, as at a date.
     *
     * The statement balances because of one line the chart does not hold:
     * revenue and expense that no closing entry has yet moved into retained
     * earnings. Double entry says every account taken together nets to zero, so
     * assets must equal liabilities plus equity plus whatever the temporary
     * accounts still carry. Between two year ends that figure is the profit
     * earned so far; after a close it is zero, because the closing entry has
     * moved it into Retained Earnings and it is read there instead. Reporting it
     * is not a convenience — leaving it out would make the statement disagree
     * with itself for most of the year.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(int $companyId, ?string $asOf = null): array
    {
        $asOf = $asOf ?? now()->toDateString();

        $accounts = $this->accountsOfType($companyId, [
            AccountType::Asset,
            AccountType::Liability,
            AccountType::Equity,
        ]);

        $balances = $this->ledger->accountBalances($companyId, $this->ids($accounts), $asOf);

        $assets = [];
        $liabilities = [];
        $equity = [];

        foreach ($accounts as $account) {
            $balance = $balances[(int) $account->getKey()] ?? Money::zero();

            if ($balance->isZero()) {
                continue;
            }

            $row = [
                'account_id' => (int) $account->getKey(),
                'account_ulid' => $account->ulid,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                // Assets read debit-positive, the ledger's own way round;
                // liabilities and equity read credit-positive.
                'amount' => $account->account_type === AccountType::Asset
                    ? $balance->toDecimal()
                    : $balance->negated()->toDecimal(),
            ];

            if ($account->account_type === AccountType::Asset) {
                $assets[] = $row;
            } elseif ($account->account_type === AccountType::Liability) {
                $liabilities[] = $row;
            } else {
                $equity[] = $row;
            }
        }

        $totalAssets = $this->total($assets);
        $totalLiabilities = $this->total($liabilities);
        $unclosedResult = $this->unclosedResult($companyId, $asOf);
        $totalEquity = $this->total($equity)->plus($unclosedResult);
        $totalOwedAndHeld = $totalLiabilities->plus($totalEquity);

        return [
            'as_of' => $asOf,
            'assets' => ['accounts' => $assets, 'total' => $totalAssets->toDecimal()],
            'liabilities' => ['accounts' => $liabilities, 'total' => $totalLiabilities->toDecimal()],
            'equity' => [
                'accounts' => $equity,
                'unclosed_result' => $unclosedResult->toDecimal(),
                'total' => $totalEquity->toDecimal(),
            ],
            'total_assets' => $totalAssets->toDecimal(),
            'total_liabilities_and_equity' => $totalOwedAndHeld->toDecimal(),
            // Stated rather than left for the reader to check, because a balance
            // sheet that does not balance is a report that is wrong rather than
            // a report that is merely uninformative.
            'balanced' => $totalAssets->equals($totalOwedAndHeld),
            'note' => 'Revenue and expense that no closing entry has yet moved into Retained Earnings '
                .'are shown in equity as the unclosed result, which is what keeps the statement '
                .'balancing between two year ends.',
        ];
    }

    /**
     * Revenue less expense as at a date, whether or not the year has been
     * closed.
     *
     * Every ledger balance is debit minus credit, and over the two temporary
     * types that sum is expense less revenue — the result the other way round.
     * Negating it gives a profit as a positive figure and a loss as a negative
     * one, the same sign convention the profit and loss statement uses.
     */
    private function unclosedResult(int $companyId, string $asOf): Money
    {
        $accounts = $this->accountsOfType($companyId, [AccountType::Revenue, AccountType::Expense]);

        return Money::sum(array_values(
            $this->ledger->accountBalances($companyId, $this->ids($accounts), $asOf)
        ))->negated();
    }

    /**
     * The company's accounts of the given types, in code order, keyed by id.
     *
     * @param  array<int, AccountType>  $types
     * @return Collection<int, Account>
     */
    private function accountsOfType(int $companyId, array $types): Collection
    {
        return Account::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('account_type', array_map(
                static fn (AccountType $type): string => $type->value,
                $types,
            ))
            ->orderBy('code')
            ->get()
            ->keyBy(static fn (Account $account): int => (int) $account->getKey());
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<int, int>
     */
    private function ids(Collection $accounts): array
    {
        return $accounts->keys()->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function total(array $rows): Money
    {
        return Money::sum(array_map(
            static fn (array $row): Money => Money::of((string) $row['amount']),
            $rows,
        ));
    }
}
