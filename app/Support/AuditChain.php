<?php

namespace App\Support;

/**
 * Canonical form of one link in the audit hash chain.
 *
 * This must stay byte-for-byte identical to the payload built by the
 * trg_audit_logs_before_insert trigger in
 * database/migrations/2026_10_27_020200_add_integrity_triggers.php.
 * The database computes the hash on insert; this class recomputes it so
 * audit:verify can detect any row that was altered after the fact.
 */
final class AuditChain
{
    /**
     * ASCII unit separator. Chosen because it cannot appear in a PHP string
     * that reached MySQL through normal application input, so two different
     * rows can never produce the same payload.
     */
    public const SEPARATOR = "\x1f";

    /**
     * Build the payload for one audit row, in the trigger's field order.
     */
    public static function payload(object $row): string
    {
        return implode(self::SEPARATOR, [
            self::text($row->previous_hash ?? null),
            self::text($row->company_id ?? null),
            self::text($row->user_id ?? null),
            self::text($row->action ?? null),
            self::text($row->auditable_type ?? null),
            self::text($row->auditable_id ?? null),
            self::text($row->ip_address ?? null),
            self::text($row->user_agent ?? null),
            self::text($row->before ?? null),
            self::text($row->after ?? null),
            self::text($row->meta ?? null),
        ]);
    }

    public static function hash(object $row): string
    {
        return hash('sha256', self::payload($row));
    }

    private static function text(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
