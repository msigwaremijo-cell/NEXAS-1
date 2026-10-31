<?php

use App\Domain\Access\Totp;

// The reference secret and codes from RFC 6238, Appendix B, for HMAC-SHA1.
// They are the whole point of this test: an implementation of TOTP that only
// agrees with itself is worth nothing, and these are the numbers every
// authenticator app on the market produces for the same inputs.
//
// The seed is the ASCII string "12345678901234567890", which base32 encodes to
// the twenty-character value below.
const NEXAS_RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('reproduces the RFC 6238 reference codes', function (int $step, string $expected): void {
    $totp = new Totp(digits: 8, period: 30, window: 0);

    expect($totp->code(NEXAS_RFC_SECRET, $step))->toBe($expected);
})->with([
    [1, '94287082'],
    [37037036, '07081804'],
    [37037037, '14050471'],
    [41152263, '89005924'],
    [66666666, '69279037'],
    [666666666, '65353130'],
]);

it('issues a code of the configured length, padded rather than shortened', function (): void {
    $totp = new Totp(digits: 6, period: 30, window: 0);

    expect($totp->code(NEXAS_RFC_SECRET, 1))->toHaveLength(6)
        ->and($totp->code(NEXAS_RFC_SECRET, 1))->toMatch('/^[0-9]{6}$/');
});

it('accepts the code for the step either side of now, and nothing further', function (): void {
    $totp = new Totp(digits: 8, period: 30, window: 1);

    $now = 1_700_000_000;

    $previous = $totp->code(NEXAS_RFC_SECRET, intdiv($now, 30) - 1);
    $current = $totp->code(NEXAS_RFC_SECRET, intdiv($now, 30));
    $next = $totp->code(NEXAS_RFC_SECRET, intdiv($now, 30) + 1);
    $tooFar = $totp->code(NEXAS_RFC_SECRET, intdiv($now, 30) + 2);

    expect($totp->verify(NEXAS_RFC_SECRET, $previous, $now))->toBeTrue()
        ->and($totp->verify(NEXAS_RFC_SECRET, $current, $now))->toBeTrue()
        ->and($totp->verify(NEXAS_RFC_SECRET, $next, $now))->toBeTrue()
        ->and($totp->verify(NEXAS_RFC_SECRET, $tooFar, $now))->toBeFalse();
});

it('refuses anything that is not exactly one code', function (string $presented): void {
    $totp = new Totp(digits: 6, period: 30, window: 1);

    expect($totp->verify(NEXAS_RFC_SECRET, $presented, 1_700_000_000))->toBeFalse();
})->with([
    'empty' => '',
    'letters' => 'abcdef',
    'too short' => '12345',
    'too long' => '1234567',
    'a fragment' => '1234',
]);

it('generates a base32 secret of the expected shape', function (): void {
    $totp = new Totp;

    $secret = $totp->generateSecret();

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        // Two secrets in a row cannot be the same one, or the generator is not
        // drawing from a random source at all.
        ->and($totp->generateSecret())->not->toBe($secret);
});

it('builds a provisioning URI an authenticator app can read', function (): void {
    $totp = new Totp(digits: 6, period: 30, window: 1);

    $uri = $totp->provisioningUri('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'owner@nexas.co.tz', 'Nexas');

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain('secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')
        ->and($uri)->toContain('issuer=Nexas')
        ->and($uri)->toContain('digits=6')
        ->and($uri)->toContain('period=30');
});

it('refuses to be constructed with a code length no client would show', function (int $digits): void {
    new Totp(digits: $digits);
})->with([5, 11])->throws(InvalidArgumentException::class);
