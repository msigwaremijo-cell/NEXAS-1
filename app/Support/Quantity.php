<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The decimal helper for quantities, alongside Money.
 *
 * A quantity is stored as DECIMAL(19,6) and is not money, so it cannot go
 * through Money: a quantity has no rounding policy and no currency, and
 * treating six decimal places of a unit as four places of a shilling would
 * quietly lose stock. What the two share is the reason for existing — a float
 * cannot round-trip either column, so both stay as decimal strings and both do
 * their arithmetic with bcmath.
 *
 * The methods are static and return strings rather than an object because a
 * quantity is only ever read, compared and added; there is no arithmetic
 * identity worth carrying around, and the cost it turns into is a Money.
 */
final class Quantity
{
    /**
     * Number of decimal places quantities are stored and reported at.
     */
    public static function scale(): int
    {
        return (int) config('nexas.quantity_scale', 6);
    }

    /**
     * Normalise to the stored scale. Floats are refused for the same reason
     * Money refuses them: the value may already be wrong before it arrives.
     *
     * @param  string|int  $value
     */
    public static function of(mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                'Quantity refuses a float because it cannot hold a DECIMAL(19,6) exactly. '
                .'Pass a decimal string such as "12.000000" instead.'
            );
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Quantity expects a decimal string or an integer, got %s.',
                get_debug_type($value)
            ));
        }

        $value = trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Quantity expects a plain decimal string such as "12.000000", got [%s].',
                $value
            ));
        }

        return bcadd($value, '0', self::scale());
    }

    public static function zero(): string
    {
        return self::of('0');
    }

    public static function add(string $left, string $right): string
    {
        return self::of(bcadd($left, $right, self::scale()));
    }

    public static function subtract(string $left, string $right): string
    {
        return self::of(bcsub($left, $right, self::scale()));
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, self::scale());
    }

    public static function isZero(string $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    /**
     * The larger of two quantities, so a draw-down takes what is there when
     * that is less than what is wanted, and what is wanted when it is not.
     */
    public static function greater(string $left, string $right): string
    {
        return self::compare($left, $right) >= 0 ? $left : $right;
    }

    /**
     * The smaller of two quantities.
     */
    public static function lesser(string $left, string $right): string
    {
        return self::compare($left, $right) <= 0 ? $left : $right;
    }

    /**
     * The magnitude without the sign, for reporting a shortfall as a positive
     * number of units.
     */
    public static function absolute(string $value): string
    {
        return self::isNegative($value) ? self::of(bcmul($value, '-1', self::scale())) : $value;
    }

    /**
     * The same quantity pointing the other way.
     *
     * The sign is the direction a movement went, so an issue is written by
     * negating what was asked for. Zero is returned as zero rather than as a
     * negative nothing, which is why this does not simply prepend a minus.
     */
    public static function negate(string $value): string
    {
        return self::of(bcmul($value, '-1', self::scale()));
    }
}
