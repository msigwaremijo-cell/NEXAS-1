<?php

namespace App\Console\Commands;

use App\Domain\Reporting\Services\LedgerVerification;
use App\Jobs\AlertLedgerDivergence;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Checks, company by company, that the ledger_balances snapshot still agrees
 * with the ledger that it caches.
 *
 * The comparison lives in LedgerVerification; this only decides which
 * companies to ask about, prints what it found and raises an alert when it
 * finds something. Nothing is repaired — a snapshot that has drifted is
 * rebuilt by RefreshLedgerBalances, and running that over books whose drift has
 * not been looked at would hide the fault rather than fix it.
 *
 * The exit code is what the scheduler reports on: a non-zero code is how a
 * nightly run that nobody reads still becomes visible.
 */
class VerifyLedgerBalances extends Command
{
    protected $signature = 'ledger:verify
        {--company= : Verify only this company id; every company is verified when omitted}';

    protected $description = 'Verify the ledger_balances snapshot still agrees with journal_lines';

    public function handle(LedgerVerification $verification): int
    {
        $companies = $this->companies();

        if ($companies === []) {
            $this->info('OK: no companies to verify.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($companies as $companyId) {
            $result = $verification->verify($companyId);

            if ($result['consistent']) {
                $this->info(sprintf(
                    'OK: company %d: ledger snapshot agrees with the ledger (%d row(s) across %d period(s)).',
                    $companyId,
                    $result['snapshots_checked'],
                    $result['periods_checked'],
                ));

                continue;
            }

            $failed = true;
            $divergences = $result['divergences'];

            $this->error(sprintf(
                'FAIL: company %d: %d divergence(s) between the ledger snapshot and the ledger.',
                $companyId,
                count($divergences),
            ));

            foreach (array_slice($divergences, 0, 20) as $divergence) {
                $this->line(sprintf(
                    '  %s, account %d, %s: snapshot %s, ledger %s',
                    $divergence['period'],
                    $divergence['account_id'],
                    $divergence['check'],
                    $divergence['snapshot'] ?? '(none)',
                    $divergence['ledger'] ?? '(none)',
                ));
            }

            if (count($divergences) > 20) {
                $this->line(sprintf('  ... and %d more.', count($divergences) - 20));
            }

            // Written rather than only printed, so a run nobody watched still
            // leaves a durable trace for whoever consumes the outbox.
            (new AlertLedgerDivergence($companyId, $divergences))->handle();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function companies(): array
    {
        $companyId = $this->option('company');

        if ($companyId !== null) {
            return [(int) $companyId];
        }

        return Company::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
