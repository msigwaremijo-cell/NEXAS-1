<?php

namespace App\Domain\Banking\Services;

use App\Support\Money;

/**
 * Reading an amount out of a bank statement cell.
 *
 * Statement exports are written by whichever reporting tool a bank happens to
 * own, so the same figure turns up as 1234.56, as 1,234.56, as "1 234.56" and,
 * in a comma-decimal country, as 1.234,56.
 *
 * Three of those four are read. The fourth is refused rather than guessed at,
 * and that refusal is the point of this class: reading 1.234,56 by stripping
 * its comma as a thousands separator turns it into 1234.56, which is the
 * figure a thousand times too large, and a reconciliation built on it balances
 * against the wrong journal lines without saying so. `looksCommaDecimal()` is
 * exposed separately so the caller can say that in the error rather than
 * reporting a generic unreadable amount.
 *
 * Nothing here rounds to the currency's decimal places: amounts are handed back
 * at the money scale, and MySQL stores DECIMAL(19,4) whatever the currency
 * claims.
 */
final class StatementAmount
{
    /**
     * The digits before the decimal point that a DECIMAL(19,4) column can hold.
     */
    public const MAX_INTEGER_DIGITS = 15;

    /**
     * A plain decimal string at the money scale, or null if the cell is not a
     * figure this importer can read.
     */
    public static function read(string $value): ?string
    {
        $normalised = self::normalise($value);

        if ($normalised === '' || self::looksCommaDecimal($normalised)) {
            return null;
        }

        // Thousands separators: either 1,234,567.89 or a bare run of digits
        // with an optional fraction. A group that is not three digits long is
        // not accepted, so "12,34" is a refusal rather than the number 1234.
        if (preg_match('/^[+-]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?$/', $normalised) !== 1) {
            return null;
        }

        return Money::of(ltrim(str_replace(',', '', $normalised), '+'))->toDecimal();
    }

    /**
     * True when the cell is laid out the way a comma-decimal export writes a
     * figure, e.g. 1.234,56 or 1,50.
     *
     * A comma followed by exactly three digits is NOT treated as a decimal
     * separator, because 1,234 reads as one thousand two hundred and thirty
     * four far more often than as one and a bit. The ambiguous case is left to
     * the ordinary reading rules.
     */
    public static function looksCommaDecimal(string $value): bool
    {
        return preg_match('/^[+-]?\d{1,3}(?:\.\d{3})*,\d{1,2}$/', self::normalise($value)) === 1;
    }

    /**
     * The cell is not a figure at all, as opposed to an empty cell: a
     * difference the caller needs, because an empty cell in a money-in column
     * is simply "no money in" while "abc" is a broken file.
     */
    public static function isBlank(string $value): bool
    {
        return self::normalise($value) === '';
    }

    /**
     * True when the figure has more digits before the decimal point than the
     * books can hold. Checked after a successful read rather than inside it, so
     * the caller can tell "not a number" from "a number too large to record".
     */
    public static function exceedsRange(string $decimal): bool
    {
        $whole = explode('.', ltrim($decimal, '-'), 2)[0];

        return strlen(ltrim($whole, '0')) > self::MAX_INTEGER_DIGITS;
    }

    /**
     * Spaces, non-breaking spaces and the apostrophe are all grouping marks a
     * statement may use. They are removed before the figure is read; the comma
     * and the full stop are left alone, because those decide the decimal point.
     */
    private static function normalise(string $value): string
    {
        return str_replace([' ', "\u{00a0}", "'"], '', trim($value));
    }
}
