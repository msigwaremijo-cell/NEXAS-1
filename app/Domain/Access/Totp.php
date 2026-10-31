<?php

namespace App\Domain\Access;

use InvalidArgumentException;

/**
 * Time-based one-time passwords (RFC 6238), the codes an authenticator app
 * shows.
 *
 * Deliberately self-contained rather than a dependency: the algorithm is a
 * short, specified piece of arithmetic, and a second factor that leans on a
 * third-party package for twenty lines of HMAC is a supply-chain surface this
 * build does not need. Everything it does is pinned by the RFC and covered by
 * the tests beside it.
 *
 * The secret is base32 because that is what authenticator apps scan and what
 * an otpauth:// URI carries; the code is the low digits of an HMAC-SHA1 over
 * the number of 30-second steps since the Unix epoch. One step of drift either
 * side of now is accepted by default, which forgives a clock a few seconds out
 * without widening the guessing window beyond a minute.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly int $window = 1,
    ) {
        if ($this->digits < 6 || $this->digits > 10) {
            throw new InvalidArgumentException('A one-time password must carry between 6 and 10 digits.');
        }

        if ($this->period < 1) {
            throw new InvalidArgumentException('A one-time password period must be at least one second.');
        }

        if ($this->window < 0) {
            throw new InvalidArgumentException('A one-time password window cannot be negative.');
        }
    }

    /**
     * The instance the configuration asks for.
     */
    public static function fromConfig(): self
    {
        return new self(
            (int) config('nexas.auth.two_factor.digits', 6),
            (int) config('nexas.auth.two_factor.period', 30),
            (int) config('nexas.auth.two_factor.window', 1),
        );
    }

    /**
     * A fresh shared secret, base32 encoded.
     *
     * Twenty bytes is the RFC 4226 recommendation for an HMAC-SHA1 key and is
     * what authenticator apps expect to be handed as 32 base32 characters.
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->encodeBase32(random_bytes($bytes));
    }

    /**
     * Whether a code is the one this secret produces around the given moment.
     *
     * Comparison is constant time, and the code must be exactly as many digits
     * as this instance issues, so a short code is not quietly accepted as a
     * prefix of a longer one.
     */
    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (strlen($code) !== $this->digits || ! ctype_digit($code)) {
            return false;
        }

        $step = intdiv($timestamp ?? time(), $this->period);

        for ($drift = -$this->window; $drift <= $this->window; $drift++) {
            if (hash_equals($this->code($secret, $step + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The code this secret produces for a given step, for the tests and for
     * anything that needs to speak for the server's clock rather than the
     * caller's.
     */
    public function code(string $secret, ?int $step = null): string
    {
        $step ??= intdiv(time(), $this->period);

        // A 64-bit counter, big endian, as two 32-bit words: the high word is
        // always zero for any time this century, and writing it that way keeps
        // the binary layout right on any platform rather than depending on a
        // 64-bit integer pack format.
        $binary = pack('N*', 0, $step);

        $hash = hash_hmac('sha1', $binary, $this->decodeBase32($secret), true);

        // Dynamic truncation: the low nibble of the last byte picks the offset
        // the four-byte window starts at, and the top bit is masked off so the
        // result is a positive 31-bit integer on every platform.
        $offset = ord($hash[19]) & 0x0F;

        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** $this->digits);

        return str_pad((string) $value, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * The otpauth:// URI an authenticator app enrolls from.
     *
     * The issuer is repeated in the label and as a parameter because clients
     * disagree about which one they read.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => $this->digits,
            'period' => $this->period,
        ]);
    }

    private function encodeBase32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function decodeBase32(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        $bits = '';

        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        // A trailing group shorter than a byte is padding and is dropped rather
        // than padded out, which would invent a key byte the encoder never had.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
