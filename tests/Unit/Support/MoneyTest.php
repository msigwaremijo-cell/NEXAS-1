<?php

use App\Support\Money;

it('refuses a float so an amount can never lose precision', function (): void {
    expect(fn () => Money::of(1.5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(0.1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('100.0000')->times(1.5))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('10.0000')->dividedBy(0.5))->toThrow(InvalidArgumentException::class);
});

it('refuses a value that is not a decimal string or an integer', function (): void {
    expect(fn () => Money::of(null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(true))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of([1]))->toThrow(InvalidArgumentException::class);
});

it('refuses anything that is not a plain decimal literal', function (): void {
    expect(fn () => Money::of('1e5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('1,234.00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::of(''))->toThrow(InvalidArgumentException::class);
});

it('quantises to the configured scale using half-up rounding', function (): void {
    expect(Money::scale())->toBe(4)
        ->and(Money::of('100.005')->toDecimal())->toBe('100.0050')
        ->and(Money::of('100.00005')->toDecimal())->toBe('100.0001')
        ->and(Money::of('0.00004')->toDecimal())->toBe('0.0000')
        ->and(Money::of('1.00005')->toDecimal())->toBe('1.0001')
        ->and(Money::of('-1.00005')->toDecimal())->toBe('-1.0001');
});

it('adds and subtracts without floating point drift', function (): void {
    expect(Money::of('0.1')->plus(Money::of('0.2'))->toDecimal())->toBe('0.3000')
        ->and(Money::of('100.0000')->minus(Money::of('0.0001'))->toDecimal())->toBe('99.9999')
        ->and(Money::sum([
            Money::of('100.0000'),
            Money::of('275.5000'),
            Money::of('19.9900'),
            Money::of('1000.0000'),
        ])->toDecimal())->toBe('1395.4900');
});

it('multiplies and divides at the money scale', function (): void {
    expect(Money::of('19.9900')->times('3')->toDecimal())->toBe('59.9700')
        ->and(Money::of('100.0000')->times('0.18')->toDecimal())->toBe('18.0000')
        ->and(Money::of('100.0000')->dividedBy('3')->toDecimal())->toBe('33.3333');
});

it('refuses to divide by zero', function (): void {
    expect(fn () => Money::of('10.0000')->dividedBy('0'))->toThrow(InvalidArgumentException::class);
});

it('compares values', function (): void {
    $ten = Money::of('10.0000');

    expect($ten->compareTo(Money::of('10')))->toBe(0)
        ->and($ten->equals(Money::of('10.0000')))->toBeTrue()
        ->and($ten->greaterThan(Money::of('9.9999')))->toBeTrue()
        ->and($ten->greaterThanOrEqualTo(Money::of('10.0000')))->toBeTrue()
        ->and($ten->lessThan(Money::of('10.0001')))->toBeTrue()
        ->and($ten->lessThanOrEqualTo(Money::of('10.0000')))->toBeTrue()
        ->and($ten->isPositive())->toBeTrue()
        ->and($ten->isZero())->toBeFalse()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::of('-0.0001')->isNegative())->toBeTrue()
        ->and(Money::of('-3.5000')->absolute()->toDecimal())->toBe('3.5000')
        ->and(Money::of('3.5000')->negated()->toDecimal())->toBe('-3.5000');
});

it('formats without a float conversion or the intl extension', function (): void {
    expect(Money::of('1234567.5000')->format('TZS'))->toBe('TZS 1,234,567.5000')
        ->and(Money::of('-1234567.5000')->format('TZS'))->toBe('-TZS 1,234,567.5000')
        ->and(Money::of('0')->format('TZS'))->toBe('TZS 0.0000')
        ->and(Money::of('123.4000')->format('TZS'))->toBe('TZS 123.4000')
        ->and((string) Money::of('5'))->toBe('5.0000');
});
