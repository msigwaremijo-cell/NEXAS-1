<?php

namespace App\Domain\Banking\Enums;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The date layouts a statement file is allowed to use.
 *
 * The set is closed on purpose. A statement line is matched to a journal line
 * by its date, so a file read one day out is a file that reconciles to the
 * wrong entries; and 03/04/2026 is the third of April in one bank's export and
 * the fourth of March in another's. Naming the layout is therefore part of the
 * mapping step rather than something the importer guesses, and refusing an
 * unnamed layout keeps a typo from silently changing the calendar.
 */
enum StatementDateFormat: string
{
    case IsoDate = 'Y-m-d';
    case IsoSlash = 'Y/m/d';
    case DayFirstSlash = 'd/m/Y';
    case DayFirstDash = 'd-m-Y';
    case DayFirstDot = 'd.m.Y';
    case Compact = 'Ymd';

    public function label(): string
    {
        return match ($this) {
            self::IsoDate => '2026-03-15',
            self::IsoSlash => '2026/03/15',
            self::DayFirstSlash => '15/03/2026',
            self::DayFirstDash => '15-03-2026',
            self::DayFirstDot => '15.03.2026',
            self::Compact => '20260315',
        };
    }

    /**
     * The date in the layout this value names, as Y-m-d, or null if the text is
     * not a complete date in it.
     *
     * The layout is anchored with `!` so the hour fields are not taken from the
     * clock, and a trailing warning is treated as a failure: PHP will happily
     * read "15/03/2026 paid" as the fifteenth of March and leave the rest
     * unread, which is exactly how a wrong line gets into a reconciliation.
     */
    public function parse(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!'.$this->value, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false) {
            return null;
        }

        if (is_array($errors) && ($errors['error_count'] > 0 || $errors['warning_count'] > 0)) {
            return null;
        }

        return $parsed->format('Y-m-d');
    }
}
