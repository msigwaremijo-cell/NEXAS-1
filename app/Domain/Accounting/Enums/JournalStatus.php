<?php

namespace App\Domain\Accounting\Enums;

/**
 * Mirrors the `journals.status` column comment ('draft|posted|reversed').
 */
enum JournalStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Reversed = 'reversed';

    /**
     * Only drafts may be edited or deleted. The database triggers enforce the
     * same rule, so this is a convenience for the application layer only.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * True once the journal has hit the ledger. Both posted and reversed
     * journals are immutable: a reversed journal still has its original lines.
     */
    public function isFinal(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * The statuses whose lines belong in the ledger.
     *
     * A draft's lines are written the moment the draft is created, so they sit
     * in journal_lines long before they belong in a report. A reversed journal
     * is the opposite case: its lines stay in the ledger for ever and are
     * cancelled by the mirror-image journal that reversed them. Filtering on
     * 'posted' alone would therefore silently remove the original entry the
     * moment it was corrected, and leave only the correction behind.
     *
     * @return list<string>
     */
    public static function ledgerStatuses(): array
    {
        return [self::Posted->value, self::Reversed->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }
}
