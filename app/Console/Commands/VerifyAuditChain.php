<?php

namespace App\Console\Commands;

use App\Jobs\AlertAuditChainBreak;
use App\Support\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the audit hash chain and reports the first row that does not
 * agree with it. Every check is read-only: nothing is repaired.
 *
 * A break is also written to the outbox as an alert, so a nightly run nobody
 * watched still leaves a durable trace for whoever consumes it rather than
 * only a line of console output that scrolled away. The exit code is what the
 * scheduler reports on.
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the SHA-256 audit hash chain is intact and append-only';

    public function handle(): int
    {
        $rows = DB::select(
            'SELECT id, company_id, user_id, action, auditable_type, auditable_id,
                    ip_address, user_agent, `before`, `after`, `meta`,
                    previous_hash, hash
               FROM audit_logs
              ORDER BY id ASC'
        );

        $expectedPrevious = null;
        $lastHash = null;
        $lastId = null;
        $checked = 0;

        foreach ($rows as $row) {
            if ($row->previous_hash !== $expectedPrevious) {
                return $this->reportBroken($row, sprintf(
                    'broken link: previous_hash is %s but the chain expected %s',
                    $this->show($row->previous_hash),
                    $this->show($expectedPrevious)
                ));
            }

            $recomputed = AuditChain::hash($row);

            if (! hash_equals($recomputed, (string) $row->hash)) {
                return $this->reportBroken($row, sprintf(
                    'content hash mismatch: stored %s, recomputed %s',
                    $this->show($row->hash),
                    $this->show($recomputed)
                ));
            }

            $expectedPrevious = $row->hash;
            $lastHash = $row->hash;
            $lastId = $row->id;
            $checked++;
        }

        $head = DB::selectOne('SELECT last_hash, last_id, chain_length FROM audit_chain_head WHERE id = 1');

        if ($head === null) {
            return $this->failAndAlert(null, 'audit_chain_head has no row, so the chain cannot be trusted.');
        }

        if ((int) $head->chain_length !== $checked) {
            return $this->failAndAlert(null, sprintf(
                'audit_chain_head reports %d links but audit_logs holds %d rows.',
                $head->chain_length,
                $checked
            ));
        }

        if ((string) $head->last_hash !== (string) $lastHash) {
            return $this->failAndAlert(null, sprintf(
                'audit_chain_head last_hash is %s but the newest audit row is %s.',
                $this->show($head->last_hash),
                $this->show($lastHash)
            ));
        }

        if ($head->last_id !== null && $head->last_id != $lastId) {
            return $this->failAndAlert(null, sprintf(
                'audit_chain_head last_id is %s but the newest audit row is %s.',
                $head->last_id,
                $lastId
            ));
        }

        $this->info(sprintf('OK: audit chain intact, %d row(s) verified, head %s.', $checked, $this->show($lastHash)));

        return self::SUCCESS;
    }

    private function reportBroken(object $row, string $reason): int
    {
        // A row written outside any company has a null company_id, and casting
        // that to int would name company 0 — which is no company at all and
        // fails the outbox foreign key, losing the very alarm this raises.
        $companyId = $row->company_id === null ? null : (int) $row->company_id;

        return $this->failAndAlert($companyId, sprintf(
            'audit chain broken at audit_logs.id = %s (%s).',
            $row->id,
            $reason
        ), (int) $row->id);
    }

    /**
     * Print the failure, write it to the outbox so an unwatched run is still
     * visible, and answer with a non-zero exit code for the scheduler.
     */
    private function failAndAlert(?int $companyId, string $reason, ?int $auditLogId = null): int
    {
        $this->error('FAIL: '.$reason);

        (new AlertAuditChainBreak($companyId, [
            'reason' => $reason,
            'audit_log_id' => $auditLogId,
            'company_id' => $companyId,
        ]))->handle();

        return self::FAILURE;
    }

    private function show(?string $hash): string
    {
        return $hash === null || $hash === '' ? '(empty)' : $hash;
    }
}
