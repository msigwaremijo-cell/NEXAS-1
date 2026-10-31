<?php

namespace App\Domain\Backups\Services;

use App\Domain\Backups\Exceptions\BackupRefusedException;
use App\Domain\Backups\Exceptions\BackupsException;

/**
 * Puts a backup file beyond the reach of anybody who can read the disk it sits
 * on, and proves on the way back in that nobody has touched it.
 *
 * The construction is AES-256-CTR for secrecy with HMAC-SHA256 over the
 * ciphertext for authenticity — encrypt-then-MAC, so a byte changed anywhere
 * in the file is caught rather than decrypted into plausible nonsense. The two
 * keys are derived from the one configured secret with HKDF and different
 * labels, so the key that encrypts is not the key that authenticates.
 *
 * Everything is done a chunk at a time against the filesystem, because a
 * backup of real books does not fit in memory and a whole-file encryption
 * would need it to. The counter for CTR mode is advanced by hand between
 * chunks, which is why every read below is padded out to whole 16-byte blocks:
 * a short read that was not block-aligned would leave the keystream out of
 * step with the ciphertext and quietly corrupt everything after it.
 *
 * Decryption reads the file twice — once to authenticate the whole thing, once
 * to write it out — so that a file that fails its check is never partially
 * written anywhere. Refusing an altered backup is worth a second pass over a
 * file a few megabytes long.
 */
final class BackupCipher
{
    /**
     * Six bytes of magic, so a file that is not a backup at all is rejected as
     * one rather than as a wrong key.
     */
    private const MAGIC = "NXBK1\0";

    /**
     * Bumped when the layout changes. A restore refuses a version it does not
     * know rather than guessing at the bytes.
     */
    private const VERSION = 1;

    private const CIPHER = 'aes-256-ctr';

    private const BLOCK_BYTES = 16;

    private const SALT_BYTES = 16;

    private const IV_BYTES = 16;

    private const MAC_BYTES = 32;

    private const KEY_BYTES = 32;

    /**
     * How much is read, encrypted and written at a time. A multiple of the
     * block size, so the CTR counter can be advanced a whole number of blocks
     * between chunks.
     */
    public const CHUNK_BYTES = 1048576;

    public function __construct(
        private readonly string $key,
        private readonly int $chunkBytes = self::CHUNK_BYTES,
    ) {}

    /**
     * The cipher this deployment is configured with.
     */
    public static function fromConfig(): self
    {
        return new self(self::decodeKey(config('nexas.backups.key')));
    }

    /**
     * A cipher under a key the caller supplies. Used by the restore drill to
     * show a wrong key is refused, and by nothing else.
     */
    public static function fromKeyString(string $key): self
    {
        return new self(self::decodeKey($key));
    }

    /**
     * The configured secret, decoded to exactly 32 bytes.
     *
     * A missing key is a refusal rather than a fallback: the file this is about
     * to wrap holds every company's books, and there is no version of that
     * which is safe to write unencrypted.
     */
    public static function decodeKey(mixed $key): string
    {
        if (! is_string($key) || trim($key) === '') {
            throw BackupsException::keyMissing();
        }

        $key = trim($key);

        if (str_starts_with($key, 'base64:')) {
            $key = substr($key, 7);
        }

        $decoded = base64_decode($key, true);

        if ($decoded === false || strlen($decoded) !== self::KEY_BYTES) {
            throw BackupsException::keyMalformed($decoded === false ? 0 : strlen($decoded));
        }

        return $decoded;
    }

    /**
     * Encrypt a file, writing the result to another.
     */
    public function encrypt(string $source, string $destination): void
    {
        $salt = random_bytes(self::SALT_BYTES);
        $iv = random_bytes(self::IV_BYTES);

        [$encryptionKey, $authenticationKey] = $this->keys($salt);

        $header = self::MAGIC.chr(self::VERSION).$salt.$iv;

        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $this->put($out, $header);

            $mac = hash_init('sha256', HASH_HMAC, $authenticationKey);
            hash_update($mac, $header);

            $counter = $iv;

            while (($chunk = fread($in, $this->chunkBytes)) !== false && $chunk !== '') {
                $ciphertext = openssl_encrypt($chunk, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $counter);

                if ($ciphertext === false) {
                    throw BackupsException::notStored(basename($destination));
                }

                $this->put($out, $ciphertext);
                hash_update($mac, $ciphertext);

                $counter = self::advance($counter, strlen($chunk));
            }

            $this->put($out, hash_final($mac, true));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Authenticate a file under this key, and only then write it out decoded.
     */
    public function decrypt(string $source, string $destination): void
    {
        $name = basename($source);
        $size = filesize($source);
        $headerBytes = strlen(self::MAGIC) + 1 + self::SALT_BYTES + self::IV_BYTES;

        if ($size === false || $size < $headerBytes + self::MAC_BYTES) {
            throw BackupRefusedException::notABackup($name);
        }

        $in = $this->open($source, 'rb');

        try {
            $header = $this->readBytes($in, $headerBytes);

            if (! str_starts_with($header, self::MAGIC) || ord($header[strlen(self::MAGIC)]) !== self::VERSION) {
                throw BackupRefusedException::notABackup($name);
            }

            $offset = strlen(self::MAGIC) + 1;
            $salt = substr($header, $offset, self::SALT_BYTES);
            $iv = substr($header, $offset + self::SALT_BYTES, self::IV_BYTES);

            [$encryptionKey, $authenticationKey] = $this->keys($salt);

            $bodyBytes = $size - $headerBytes - self::MAC_BYTES;

            // First pass: authenticate the whole file before a byte of it is
            // trusted. Nothing below this point runs on a file that fails here.
            $mac = hash_init('sha256', HASH_HMAC, $authenticationKey);
            hash_update($mac, $header);

            $remaining = $bodyBytes;

            while ($remaining > 0) {
                $chunk = fread($in, min($this->chunkBytes, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw BackupRefusedException::notABackup($name);
                }

                hash_update($mac, $chunk);
                $remaining -= strlen($chunk);
            }

            $stored = $this->readBytes($in, self::MAC_BYTES);

            if (! hash_equals(hash_final($mac, true), $stored)) {
                throw BackupRefusedException::willNotOpen($name);
            }

            // Second pass: the file is authentic, so now it may be decoded.
            rewind($in);
            $this->readBytes($in, $headerBytes);

            $out = $this->open($destination, 'wb');

            try {
                $counter = $iv;
                $remaining = $bodyBytes;

                while ($remaining > 0) {
                    $chunk = $this->readBytes($in, min($this->chunkBytes, $remaining));

                    if ($chunk === '') {
                        throw BackupRefusedException::notABackup($name);
                    }

                    $plaintext = openssl_decrypt($chunk, self::CIPHER, $encryptionKey, OPENSSL_RAW_DATA, $counter);

                    if ($plaintext === false) {
                        throw BackupRefusedException::willNotOpen($name);
                    }

                    $this->put($out, $plaintext);
                    $counter = self::advance($counter, strlen($chunk));
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($out);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * The two keys, derived from the one secret with different labels so that
     * the key doing the authenticating is not the key doing the encrypting.
     *
     * @return array{0: string, 1: string}
     */
    private function keys(string $salt): array
    {
        return [
            hash_hkdf('sha256', $this->key, self::KEY_BYTES, 'nexas-backup-encryption', $salt),
            hash_hkdf('sha256', $this->key, self::KEY_BYTES, 'nexas-backup-authentication', $salt),
        ];
    }

    /**
     * The counter block for the next chunk, a whole number of blocks on.
     *
     * CTR turns a block cipher into a stream by encrypting a counter, so every
     * block encrypted must advance it by one. Openssl restarts from the IV it
     * is handed on each call, which is why this is done here rather than left
     * to the call.
     */
    private static function advance(string $counter, int $bytes): string
    {
        $blocks = intdiv($bytes + self::BLOCK_BYTES - 1, self::BLOCK_BYTES);
        $carry = $blocks;

        for ($index = strlen($counter) - 1; $index >= 0 && $carry > 0; $index--) {
            $sum = ord($counter[$index]) + ($carry & 0xFF);
            $counter[$index] = chr($sum & 0xFF);
            $carry = ($carry >> 8) + ($sum >> 8);
        }

        return $counter;
    }

    /**
     * Read exactly this many bytes, or fail.
     *
     * A single fread may return short of what was asked for, and a short read
     * in the middle of the file would leave the keystream out of step.
     */
    private function readBytes($handle, int $length): string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = fread($handle, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param  resource  $handle
     */
    private function put($handle, string $bytes): void
    {
        if (fwrite($handle, $bytes) !== strlen($bytes)) {
            throw BackupsException::notStored('backup');
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);

        if ($handle === false) {
            throw BackupsException::notStored(basename($path));
        }

        return $handle;
    }
}
