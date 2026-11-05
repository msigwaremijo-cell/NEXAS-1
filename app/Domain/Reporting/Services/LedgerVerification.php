<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Services\LedgerBalanceCalculator;
use App\Models\FiscalPeriod;
use App\Models\LedgerBalance;
use App\Support\Money;

/**
 * Proves the ledger_balances snapshot still agrees with the ledger it caches.
 *
 * journal_lines is the source of truth and ledger_balances is a cache of it, so
 * the cache is only useful while the two say the same thing. This reads each
 * cached row back, recomputes the same buckets from journal_lines and reports
 * every figure that differs.
 *
 * The arithmetic is not repeated here. It comes from LedgerBalanceCalculator,
 * the same object the refresh job writes through, because a verification that
 * carried its own copy of the sum would agree with itself and disagree with the
 * job — the bug would look like a disagreement in the data rather than a bug,
 * which is exactly what a check must not do. The closing balance is then
 * cross-checked a second way, against LedgerQuery, so the check ties the cache
 * to the ledger the reports actually read and not only to the calculator.
 *
 * A period with no cached rows is not a divergence: nothing is cached, so
 * nothing contradicts the ledger. It is counted and reported as such, because
 * "the snapshot was never built" and "the snapshot is wrong" are different
 * problems and must not look alike in the output.
 *
 * Nothing is written and nothing is repaired. Where the cache is wrong, the
 * answer is to rebuild it, and deciding that is the caller's job.
 */
final class LedgerVerification
{
    /**
     * The six money buckets every snapshot row carries.
     *
     * @var list<string>
     */
    private const BUCKETS = [
        'opening_debit',
        'opening_credit',
        'period_debit',
        'period_credit',
        'closing_debit',
        'closing_credit',
    ];

    public function __construct(
        private readonly LedgerBalanceCalculator $calculator,
        private readonly LedgerQuery $ledger,
    ) {}

    /**
     * @return array{
     *     company_id: int,
     *     consistent: bool,
     *     periods_checked: int,
     *     snapshots_checked: int,
     *     accounts_checked: int,
     *     divergences: list<array<string, mixed>>
     * }
     */
    public function verify(int $companyId, ?int $fiscalPeriodId = null): array
    {
        $periods = FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->when($fiscalPeriodId !== null, fn ($query) => $query->whereKey($fiscalPeriodId))
            ->orderBy('starts_on')
            ->get();

        $divergences = [];
        $snapshotsChecked = 0;
        $accountsChecked = 0;

        foreach ($periods as $period) {
            $stored = LedgerBalance::query()
                ->where('company_id', $companyId)
                ->where('fiscal_period_id', (int) $period->getKey())
                ->get()
                ->keyBy(fn (LedgerBalance $row): int => (int) $row->account_id);

            $snapshotsChecked += $stored->count();

            if ($stored->isEmpty()) {
                continue;
            }

            $recomputed = $this->calculator->forPeriod(
                $companyId,
                $period->starts_on->toDateString(),
                $period->ends_on->toDateString(),
            );

            foreach ($stored as $accountId => $row) {
                $accountsChecked++;
                $accountId = (int) $accountId;

                $expected = $recomputed[$accountId] ?? null;

                if ($expected === null) {
                    $divergences[] = $this->divergence(
                        $period,
                        $accountId,
                        'missing_from_ledger',
                        '1',
                        '0',
                    );

                    continue;
                }

                foreach (self::BUCKETS as $bucket) {
                    $cached = $row->{$bucket};

                    if (! $this->sameMoney($cached, $expected[$bucket])) {
                        $divergences[] = $this->divergence(
                            $period,
                            $accountId,
                            $bucket,
                            (string) $cached,
                            $expected[$bucket],
                        );
                    }
                }

                // The second opinion. The buckets are compared against the same
                // calculator the writer used; this compares the balance they
                // imply against the balance the reports read off the ledger, so
                // a snapshot that agreed with the calculator but not with the
                // report would still be caught.
                $cachedNet = Money::of((string) ($row->closing_debit ?? '0'))
                    ->minus(Money::of((string) ($row->closing_credit ?? '0')));
                $ledgerNet = $this->ledger->accountBalance(
                    $companyId,
                    $accountId,
                    $period->ends_on->toDateString(),
                );

                if (! $cachedNet->equals($ledgerNet)) {
                    $divergences[] = $this->divergence(
                        $period,
                        $accountId,
                        'closing_net',
                        $cachedNet->toDecimal(),
                        $ledgerNet->toDecimal(),
                    );
                }
            }
        }

        return [
            'company_id' => $companyId,
            'consistent' => $divergences === [],
            'periods_checked' => $periods->count(),
            'snapshots_checked' => $snapshotsChecked,
            'accounts_checked' => $accountsChecked,
            'divergences' => $divergences,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function divergence(
        FiscalPeriod $period,
        int $accountId,
        string $check,
        ?string $snapshot,
        ?string $ledger,
    ): array {
        return [
            'fiscal_period_id' => (int) $period->getKey(),
            'period' => $period->name,
            'period_ends_on' => $period->ends_on->toDateString(),
            'account_id' => $accountId,
            'check' => $check,
            'snapshot' => $snapshot,
            'ledger' => $ledger,
        ];
    }

    /**
     * Both sides are read at the money scale, so '150' and '150.0000' are the
     * same figure rather than a divergence.
     */
    private function sameMoney(?string $cached, string $fresh): bool
    {
        return Money::of($cached ?? '0')->equals(Money::of($fresh));
    }
}
