<?php

use App\Domain\Backups\Exceptions\BackupRefusedException;
use App\Domain\Backups\Exceptions\BackupsException;
use App\Domain\Backups\Services\BackupCipher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

// A backup is every company's books in one file, and the only thing between it
// and anybody who can read the disk is this key. These tests pin the four
// things that make that true: the file decrypts back to exactly what went in,
// the plaintext cannot be read out of the file, the wrong key is refused, and a
// byte changed anywhere in the file is refused rather than decrypted into
// plausible nonsense.
//
// The chunk size is deliberately small in the round-trip test. The real one is
// a megabyte, and a file that fits in a single chunk would never exercise the
// part of the cipher that carries the CTR counter from one chunk to the next —
// which is exactly the part a mistake would leave quietly corrupting everything
// after the first chunk.
//
// Nothing here touches the database. The cipher works on files.
function nxCipherKey(int $byte = 7): string
{
    return 'base64:'.base64_encode(str_repeat(chr($byte), 32));
}

function nxCipherDir(): string
{
    $directory = sys_get_temp_dir().'/nexas_cipher_test_'.Str::ulid();

    File::ensureDirectoryExists($directory);

    return $directory;
}

function nxCipherWrite(string $directory, string $contents, string $name = 'plain.bin'): string
{
    $path = $directory.'/'.$name;

    file_put_contents($path, $contents);

    return $path;
}

/*
|--------------------------------------------------------------------------
| Out and back
|--------------------------------------------------------------------------
*/

it('decrypts a file back to exactly what was encrypted, across chunks', function () {
    $directory = nxCipherDir();

    try {
        $key = BackupCipher::decodeKey(nxCipherKey());

        // Sixty-four byte chunks, so the plaintext below spans many chunks and
        // the counter that drives CTR mode has to be carried correctly between
        // them for the file to come back whole.
        $plaintext = '';

        for ($index = 0; $index < 40; $index++) {
            $plaintext .= str_pad('line '.$index.' ', 53, '.', STR_PAD_RIGHT);
        }

        $source = nxCipherWrite($directory, $plaintext);
        $encrypted = $directory.'/backup.nxb';
        $decoded = $directory.'/decoded.bin';

        (new BackupCipher($key, chunkBytes: 64))->encrypt($source, $encrypted);
        (new BackupCipher($key, chunkBytes: 64))->decrypt($encrypted, $decoded);

        expect(file_get_contents($decoded))->toBe($plaintext)
            // The header and the tag are the only bytes the file carries that
            // the plaintext does not: 6 of magic, 1 of version, 16 of salt, 16
            // of counter block and a 32 byte tag.
            ->and(filesize($encrypted))->toBe(strlen($plaintext) + 39 + 32);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('does not leave the plaintext readable inside the file', function () {
    $directory = nxCipherDir();

    try {
        $marker = 'NEXAS-SECRET-MARKER-'.Str::random(24);
        $plaintext = str_repeat('filler. ', 200).$marker;

        $source = nxCipherWrite($directory, $plaintext);
        $encrypted = $directory.'/backup.nxb';

        BackupCipher::fromKeyString(nxCipherKey())->encrypt($source, $encrypted);

        expect(file_get_contents($encrypted))
            ->not->toContain($marker)
            ->not->toContain('filler.')
            ->toStartWith("NXBK1\0");
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| Refusals
|--------------------------------------------------------------------------
*/

it('refuses a file opened under a different key', function () {
    $directory = nxCipherDir();

    try {
        $source = nxCipherWrite($directory, str_repeat('the books. ', 100));
        $encrypted = $directory.'/backup.nxb';
        $decoded = $directory.'/decoded.bin';

        BackupCipher::fromKeyString(nxCipherKey(7))->encrypt($source, $encrypted);

        expect(fn () => BackupCipher::fromKeyString(nxCipherKey(99))->decrypt($encrypted, $decoded))
            ->toThrow(BackupRefusedException::class, 'does not authenticate');

        // Refused before a byte of it was written anywhere.
        expect(file_exists($decoded))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a file with a single changed byte', function () {
    $directory = nxCipherDir();

    try {
        $key = nxCipherKey();
        $source = nxCipherWrite($directory, str_repeat('the books. ', 100));
        $encrypted = $directory.'/backup.nxb';
        $decoded = $directory.'/decoded.bin';

        BackupCipher::fromKeyString($key)->encrypt($source, $encrypted);

        // One flipped bit, inside the ciphertext rather than the header. The
        // key is the right one; the file is not the file that was written.
        $bytes = (string) file_get_contents($encrypted);
        $offset = 39 + 10;
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
        file_put_contents($encrypted, $bytes);

        expect(fn () => BackupCipher::fromKeyString($key)->decrypt($encrypted, $decoded))
            ->toThrow(BackupRefusedException::class, 'does not authenticate');

        expect(file_exists($decoded))->toBeFalse();
    } finally {
        File::deleteDirectory($directory);
    }
});

it('refuses a file that is not a backup at all', function () {
    $directory = nxCipherDir();

    try {
        $cipher = BackupCipher::fromKeyString(nxCipherKey());

        $notABackup = nxCipherWrite($directory, str_repeat('x', 200), 'notes.txt');
        $tooShort = nxCipherWrite($directory, 'tiny', 'tiny.bin');

        expect(fn () => $cipher->decrypt($notABackup, $directory.'/out-a'))
            ->toThrow(BackupRefusedException::class, 'is not a Nexas backup')
            ->and(fn () => $cipher->decrypt($tooShort, $directory.'/out-b'))
            ->toThrow(BackupRefusedException::class, 'is not a Nexas backup');
    } finally {
        File::deleteDirectory($directory);
    }
});

/*
|--------------------------------------------------------------------------
| The key itself
|--------------------------------------------------------------------------
*/

it('refuses to run without a key, and refuses a key that is not 32 bytes', function () {
    expect(fn () => BackupCipher::decodeKey(null))
        ->toThrow(BackupsException::class, 'No backup key is configured')
        ->and(fn () => BackupCipher::decodeKey(''))
        ->toThrow(BackupsException::class, 'No backup key is configured')
        ->and(fn () => BackupCipher::decodeKey('   '))
        ->toThrow(BackupsException::class, 'No backup key is configured')
        ->and(fn () => BackupCipher::decodeKey('base64:'.base64_encode('short')))
        ->toThrow(BackupsException::class, 'decodes to 5 bytes')
        ->and(fn () => BackupCipher::decodeKey('not base64 at all!'))
        ->toThrow(BackupsException::class, 'decodes to 0 bytes');
});

it('reads the key from configuration, with or without the base64 prefix', function () {
    config(['nexas.backups.key' => nxCipherKey()]);

    expect(BackupCipher::fromConfig())->toBeInstanceOf(BackupCipher::class);

    // The prefix is what `php artisan key:generate` writes; a key pasted
    // without it is the same key and must work the same way.
    config(['nexas.backups.key' => substr(nxCipherKey(), 7)]);

    expect(BackupCipher::fromConfig())->toBeInstanceOf(BackupCipher::class);

    config(['nexas.backups.key' => null]);

    expect(fn () => BackupCipher::fromConfig())
        ->toThrow(BackupsException::class, 'No backup key is configured');
});
