<?php

namespace App\Support;

use InvalidArgumentException;
use Stringable;

/**
 * Immutable monetary value object.
 *
 * Every money column in Nexas is a MySQL DECIMAL(19,4). PHP floats carry about
 * 15-16 significant digits, so a float cannot round-trip a 19-digit amount.
 * This object therefore keeps the amount as a plain decimal string at the
 * configured money scale and does all arithmetic with bcmath.
 *
 * Floats are rejected on purpose at every entry point: `Money::of(0.1)` throws
 * instead of quietly losing the value. Callers pass decimal strings or ints.
 * The rejection is done inside the methods rather than with a `string|int`
 * parameter type, because PHP's default weak typing would coerce a float to an
 * int before the method body ran.
 */
final class Money implements Stringable
{
    /**
     * Intermediate precision used for multiplication and division. It is far
     * above the storage scale so a single operation cannot be rounded twice.
     */
    private const WORKING_SCALE = 12;

    private function __construct(private readonly string $amount) {}

    /**
     * Number of decimal places money is stored and reported at.
     */
    public static function scale(): int
    {
        return (int) config('nexas.money_scale', 4);
    }

    /**
     * The parameter is typed mixed on purpose. Declaring `string|int` would let
     * PHP quietly coerce 1.5 to 1 under its default weak typing, so the float
     * would be lost before this method ever ran. The rejection has to happen
     * inside.
     *
     * @param  string|int|self  $amount
     */
    public static function of(mixed $amount): self
    {
        if ($amount instanceof self) {
            return $amount;
        }

        return new self(self::quantise(self::scalar($amount)));
    }

    public static function zero(): self
    {
        return new self(self::pad('0'));
    }

    /**
     * @param  array<int, self>  $amounts
     */
    public static function sum(array $amounts): self
    {
        $total = '0';

        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount->amount, self::WORKING_SCALE);
        }

        return new self(self::quantise($total));
    }

    public function plus(self $other): self
    {
        return new self(self::quantise(bcadd($this->amount, $other->amount, self::WORKING_SCALE)));
    }

    public function minus(self $other): self
    {
        return new self(self::quantise(bcsub($this->amount, $other->amount, self::WORKING_SCALE)));
    }

    /**
     * @param  string|int  $factor
     */
    public function times(mixed $factor): self
    {
        return new self(self::quantise(bcmul($this->amount, self::scalar($factor), self::WORKING_SCALE)));
    }

    /**
     * @param  string|int  $divisor
     */
    public function dividedBy(mixed $divisor): self
    {
        $divisor = self::scalar($divisor);

        if (bccomp($divisor, '0', self::WORKING_SCALE) === 0) {
            throw new InvalidArgumentException('Money cannot be divided by zero.');
        }

        return new self(self::quantise(bcdiv($this->amount, $divisor, self::WORKING_SCALE)));
    }

    public function negated(): self
    {
        return new self(self::quantise(bcmul($this->amount, '-1', self::WORKING_SCALE)));
    }

    public function absolute(): self
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    public function compareTo(self $other): int
    {
        return bccomp($this->amount, $other->amount, self::scale());
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function greaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->compareTo(self::zero()) === 0;
    }

    public function isPositive(): bool
    {
        return $this->compareTo(self::zero()) > 0;
    }

    public function isNegative(): bool
    {
        return $this->compareTo(self::zero()) < 0;
    }

    /**
     * Value exactly as it should be written to a DECIMAL(19,4) column.
     */
    public function toDecimal(): string
    {
        return $this->amount;
    }

    /**
     * Human readable form, e.g. "TZS 1,234.5000".
     *
     * Grouping is done on the digit string rather than through number_format()
     * so no float conversion happens and no intl extension is required.
     */
    public function format(?string $currencyCode = null): string
    {
        $currency = $currencyCode ?? (string) config('nexas.default_currency', 'TZS');

        $negative = str_starts_with($this->amount, '-');
        $absolute = $negative ? substr($this->amount, 1) : $this->amount;
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');

        $body = self::group($whole).($fraction === '' ? '' : '.'.$fraction);

        return trim(($negative ? '-' : '').$currency.' '.$body);
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    /**
     * Round to the money scale using the configured rounding mode.
     */
    private static function quantise(string $value): string
    {
        $scale = self::scale();

        if (bccomp($value, '0', self::WORKING_SCALE) === 0) {
            return self::pad('0');
        }

        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $factor = bcpow('10', (string) $scale, 0);
        $precision = self::WORKING_SCALE + $scale + 4;

        $shifted = bcmul($absolute, $factor, $precision);

        // Adding 0.5 and truncating is round-half-up for the absolute value;
        // bcadd() drops the fraction because its scale is 0.
        $shifted = config('nexas.rounding_mode') === 'half_up'
            ? bcadd($shifted, '0.5', 0)
            : bcadd($shifted, '0', 0);

        $result = self::pad(bcdiv($shifted, $factor, $scale));

        return $negative && bccomp($result, '0', $scale) !== 0 ? '-'.$result : $result;
    }

    /**
     * Pad a decimal string out to the money scale without changing its value.
     */
    private static function pad(string $value): string
    {
        return bcadd($value, '0', self::scale());
    }

    /**
     * Insert thousands separators into a run of digits.
     */
    private static function group(string $digits): string
    {
        $grouped = '';
        $length = strlen($digits);

        for ($index = 0; $index < $length; $index++) {
            if ($index > 0 && ($length - $index) % 3 === 0) {
                $grouped .= ',';
            }

            $grouped .= $digits[$index];
        }

        return $grouped;
    }

    /**
     * Normalise a scalar into a decimal string, refusing anything that could
     * have already lost value. Accepts only a decimal string or an int; a
     * float, bool, null or array is an error the caller has to fix.
     */
    private static function scalar(mixed $value): string
    {
        if (is_float($value)) {
            throw new InvalidArgumentException(
                'Money refuses a float because it cannot hold a DECIMAL(19,4) exactly. '
                .'Pass a decimal string such as "1.5000" instead.'
            );
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Money expects a decimal string or an integer, got %s.',
                get_debug_type($value)
            ));
        }

        return self::decimal($value);
    }

    /**
     * Accept only a plain decimal literal. Scientific notation, thousands
     * separators and floats are all rejected so the caller has to be explicit.
     */
    private static function decimal(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Money expects a plain decimal string such as "1234.5000", got [%s].',
                $value
            ));
        }

        return $value;
    }
}
