<?php

namespace App\Domain\Importing\Services;

use App\Domain\Banking\Services\StatementAmount;
use App\Domain\Importing\DTOs\ImportRowError;
use App\Domain\Importing\Exceptions\ImportRowRefusal;
use App\Support\Money;
use App\Support\Quantity;
use InvalidArgumentException;

/**
 * Reading one cell of an import file.
 *
 * Every value an importer takes out of a row comes through here, so the reading
 * of an amount, a quantity, a yes-or-no and a code is decided once rather than
 * once per dataset. Each method throws an ImportRowRefusal carrying the
 * sentence a person should read; the importer's loop turns that into one entry
 * on the report and carries on to the next row.
 *
 * The amount reader is the bank statement's own, deliberately. A migration
 * export is written by the same kind of tool as a statement export, so the same
 * figures turn up — 1234.56, 1,234.56, "1 234.56" and, in a comma-decimal
 * country, 1.234,56 — and reading the last of those by stripping its comma as a
 * thousands separator would turn it into a figure a thousand times too large.
 * It is refused here for the same reason it is refused there.
 */
final class CellReader
{
    /**
     * The longest a code, a name or any other piece of text may be. Only used
     * where a caller does not know its own column's width.
     */
    public const DEFAULT_TEXT_LIMIT = 255;

    public static function text(
        ?string $raw,
        string $field,
        string $label,
        int $line,
        int $limit = self::DEFAULT_TEXT_LIMIT,
        bool $required = false,
    ): ?string {
        $value = trim((string) $raw);

        if ($value === '') {
            if ($required) {
                throw ImportRowRefusal::of(ImportRowError::missing($line, $field, $label));
            }

            return null;
        }

        if (mb_strlen($value) > $limit) {
            throw ImportRowRefusal::of(ImportRowError::tooLong($line, $field, $label, $limit));
        }

        return $value;
    }

    /**
     * An amount, at the money scale. An empty cell reads as nothing rather than
     * as an error unless the caller says the column is required.
     */
    public static function amount(
        ?string $raw,
        string $field,
        string $label,
        int $line,
        bool $required = false,
        bool $allowNegative = false,
    ): string {
        $value = trim((string) $raw);

        if ($value === '') {
            if ($required) {
                throw ImportRowRefusal::of(ImportRowError::missing($line, $field, $label));
            }

            return Money::zero()->toDecimal();
        }

        $decimal = StatementAmount::read($value);

        if ($decimal === null) {
            throw ImportRowRefusal::of(
                StatementAmount::looksCommaDecimal($value)
                    ? ImportRowError::commaDecimal($line, $field, $label, $value)
                    : ImportRowError::unreadableAmount($line, $field, $label, $value)
            );
        }

        if (StatementAmount::exceedsRange($decimal)) {
            throw ImportRowRefusal::of(ImportRowError::amountOutOfRange($line, $field, $label, $value));
        }

        if (! $allowNegative && bccomp($decimal, '0', Money::scale()) < 0) {
            throw ImportRowRefusal::of(ImportRowError::negative($line, $field, $label, $value));
        }

        return Money::of($decimal)->toDecimal();
    }

    /**
     * A quantity, at the quantity scale. Zero is allowed; a negative is not,
     * because nothing opening is counted below nothing.
     */
    public static function quantity(
        ?string $raw,
        string $field,
        string $label,
        int $line,
        bool $required = false,
    ): string {
        $value = trim((string) $raw);

        if ($value === '') {
            if ($required) {
                throw ImportRowRefusal::of(ImportRowError::missing($line, $field, $label));
            }

            return Quantity::zero();
        }

        // Grouping marks are removed; a comma is refused rather than guessed
        // at, for the same reason the amount reader refuses it: a comma may be
        // a thousands separator or a decimal point and nothing in the cell
        // settles which.
        $clean = str_replace([' ', "\u{00a0}", "'"], '', $value);

        try {
            $quantity = Quantity::of($clean);
        } catch (InvalidArgumentException) {
            throw ImportRowRefusal::of(ImportRowError::unreadableQuantity($line, $field, $label, $value));
        }

        if (bccomp($quantity, '0', Quantity::scale()) < 0) {
            throw ImportRowRefusal::of(ImportRowError::negative($line, $field, $label, $value));
        }

        return $quantity;
    }

    /**
     * A yes or a no. An empty cell takes the default rather than being a
     * mistake, because most of these columns are flags with an obvious
     * ordinary answer.
     */
    public static function flag(
        ?string $raw,
        string $field,
        string $label,
        int $line,
        bool $default = true,
    ): bool {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            return $default;
        }

        return match ($value) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => throw ImportRowRefusal::of(ImportRowError::unreadableFlag($line, $field, $label, trim((string) $raw))),
        };
    }

    /**
     * A whole number, such as a payment term in days.
     *
     * Read as a string and range-checked rather than handed to (int) directly,
     * because "30 days" and " 30 " both cast to something a caller would not
     * expect from a column that says it holds days.
     */
    public static function integer(
        ?string $raw,
        string $field,
        string $label,
        int $line,
        int $default = 0,
        int $min = 0,
        int $max = PHP_INT_MAX,
    ): int {
        $value = trim((string) $raw);

        if ($value === '') {
            return $default;
        }

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw ImportRowRefusal::of(ImportRowError::unreadable($line, $field, $label, $value));
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            throw ImportRowRefusal::of(ImportRowError::unreadable($line, $field, $label, $value));
        }

        return $number;
    }

    /**
     * One of a fixed set of values, read case-insensitively.
     *
     * @param  list<string>  $allowed
     */
    public static function option(
        ?string $raw,
        string $field,
        string $label,
        int $line,
        array $allowed,
        ?string $default = null,
        bool $required = false,
    ): ?string {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            if ($default !== null) {
                return $default;
            }

            if ($required) {
                throw ImportRowRefusal::of(ImportRowError::missing($line, $field, $label));
            }

            return null;
        }

        if (! in_array($value, $allowed, true)) {
            throw ImportRowRefusal::of(ImportRowError::unreadableOption($line, $field, $label, trim((string) $raw), $allowed));
        }

        return $value;
    }
}
