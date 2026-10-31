<?php

namespace App\Domain\Backups\Services;

use App\Domain\Backups\DTOs\BundlePart;
use App\Domain\Backups\Exceptions\BackupRefusedException;
use App\Domain\Backups\Exceptions\BackupsException;

/**
 * The container that holds a backup's parts: the database dump and the
 * documents filed against it, in one file.
 *
 * It is deliberately not a tar or a zip. Those formats carry their own
 * semantics — timestamps, owners, permissions, sometimes the ability to name a
 * part in a way that writes outside the folder it is unpacked into — and none
 * of that is wanted here. What is wanted is the smallest thing that can be
 * walked forwards: a header that lists every part with its size and checksum,
 * followed by the parts' bytes in the same order.
 *
 * The header is what makes the container readable in one pass and verifiable
 * as it goes. A reader never has to trust a length it has not checked: it knows
 * from the header how long each part is and what it must hash to, reads exactly
 * that many bytes, and refuses the whole bundle if the bytes do not agree with
 * what was recorded. A part that came up short is reported as truncated; a part
 * that came up the wrong length of bytes is reported as altered; a part whose
 * name would reach outside the destination is refused before a single byte of
 * it is written.
 *
 * The format, all big-endian:
 *
 *     "NXBN1\0"          six bytes of magic
 *     1 byte             format version
 *     4 bytes            how many parts follow
 *     per part:
 *       2 bytes          length of the name
 *       n bytes          the name
 *       8 bytes          the part's size in bytes
 *       32 bytes         the part's SHA-256, raw
 *     the parts' bytes   concatenated, in the order the header listed them
 *
 * This file carries no encryption. That is BackupCipher's job, and the two are
 * stacked: the bundle is written to a temporary file in the clear, the cipher
 * wraps it, and the temporary bundle is removed. Nothing here ever touches the
 * disk a backup is finally kept on.
 */
final class BackupBundle
{
    /**
     * Six bytes of magic, so a file that is not a bundle is refused as one
     * rather than as a corrupted part.
     */
    private const MAGIC = "NXBN1\0";

    /**
     * Bumped when the layout changes. A reader refuses a version it does not
     * know rather than guessing at the bytes.
     */
    private const VERSION = 1;

    /**
     * The longest name a part may have. Generous for a ULID filename nested a
     * couple of directories deep, and short enough that a corrupt length field
     * cannot ask the reader to swallow the rest of the file as a name.
     */
    private const MAX_NAME_BYTES = 4096;

    /**
     * How much is read and written at a time, so a bundle larger than memory
     * still survives the round trip.
     */
    private const CHUNK_BYTES = 1048576;

    public function __construct(
        private readonly int $chunkBytes = self::CHUNK_BYTES,
    ) {}

    /**
     * Write a bundle from a set of named source files.
     *
     * The sources are an ordered map of part name to the path the content is
     * read from. The name and the path are separate on purpose: a filed
     * document's name inside the bundle describes where it belongs in the
     * restore, while its path is wherever the store happens to keep it now.
     *
     * Every source is measured and hashed first, so the header can be written
     * before any content. The content is then streamed in the same order and
     * re-checked against the header as it goes: a file that changed between
     * the two passes is caught here rather than becoming a bundle that only
     * fails much later, at a restore, when the original may be long gone.
     *
     * @param  array<string, string>  $sources  part name => absolute source path
     * @return list<BundlePart> the manifest, in the order it was written
     */
    public function write(array $sources, string $destination): array
    {
        $parts = [];

        foreach ($sources as $name => $path) {
            $parts[] = $this->inspect((string) $name, (string) $path);
        }

        $out = $this->open($destination, 'wb');

        try {
            $this->put($out, self::MAGIC.chr(self::VERSION).pack('N', count($parts)));

            foreach ($parts as $part) {
                $this->put($out, pack('n', strlen($part->name)).$part->name.pack('J', $part->size).hex2bin($part->checksum));
            }

            foreach ($parts as $part) {
                $this->stream((string) $sources[$part->name], $out, $part);
            }
        } finally {
            fclose($out);
        }

        return $parts;
    }

    /**
     * Read a bundle's header without unpacking any of it.
     *
     * A restore drill wants to report what travelled — how many documents, the
     * database among them — before it commits to writing anything out, and this
     * answers that from the header alone.
     *
     * @return list<BundlePart>
     */
    public function readManifest(string $bundle): array
    {
        $name = basename($bundle);
        $size = filesize($bundle);

        if ($size === false) {
            throw BackupRefusedException::notABackup($name);
        }

        $in = $this->open($bundle, 'rb');

        try {
            [$parts] = $this->parse($in, (int) $size, $name);
        } finally {
            fclose($in);
        }

        return $parts;
    }

    /**
     * Unpack a bundle into a directory, verifying every part on the way out.
     *
     * Nothing is written until the header has been read and each name has been
     * checked against the destination: a bundle that names one unsafe part
     * refuses the whole extraction before any of it lands. Each part is then
     * written and hashed in the same pass, and a part whose bytes do not hash
     * to what the header recorded stops the extraction where it stands — the
     * caller is expected to be writing into a scratch directory it will throw
     * away, so a half-unpacked bundle never becomes anything anybody trusts.
     *
     * @return list<BundlePart> the manifest, so the caller can report counts
     */
    public function extract(string $bundle, string $directory): array
    {
        $name = basename($bundle);
        $size = filesize($bundle);

        if ($size === false) {
            throw BackupRefusedException::notABackup($name);
        }

        $in = $this->open($bundle, 'rb');

        try {
            [$parts] = $this->parse($in, (int) $size, $name);

            foreach ($parts as $part) {
                $target = $this->target($directory, $part->name);

                $this->ensureDirectory(dirname($target));

                $out = $this->open($target, 'wb');

                try {
                    $this->copy($in, $out, $part);
                } finally {
                    fclose($out);
                }
            }
        } finally {
            fclose($in);
        }

        return $parts;
    }

    /**
     * Measure a source file and describe it as a part.
     */
    private function inspect(string $name, string $path): BundlePart
    {
        $this->guardName($name);

        if (! is_file($path)) {
            throw BackupsException::notStored($name);
        }

        $size = filesize($path);
        $checksum = hash_file('sha256', $path);

        if ($size === false || $checksum === false) {
            throw BackupsException::notStored($name);
        }

        return new BundlePart($name, (int) $size, $checksum);
    }

    /**
     * Read the fixed header and the manifest, leaving the handle at the first
     * byte of content.
     *
     * @param  resource  $handle
     * @return array{0: list<BundlePart>, 1: int} the manifest and how many
     *                                            bytes the header took
     */
    private function parse($handle, int $size, string $bundle): array
    {
        $fixedBytes = strlen(self::MAGIC) + 1 + 4;

        if ($size < $fixedBytes) {
            throw BackupRefusedException::notABackup($bundle);
        }

        $fixed = $this->field($handle, $fixedBytes, $bundle);

        if (! str_starts_with($fixed, self::MAGIC) || ord($fixed[strlen(self::MAGIC)]) !== self::VERSION) {
            throw BackupRefusedException::notABackup($bundle);
        }

        $count = (int) unpack('N', substr($fixed, strlen(self::MAGIC) + 1, 4))[1];

        $parts = [];
        $seen = [];
        $manifestBytes = 0;

        for ($index = 0; $index < $count; $index++) {
            $nameLength = (int) unpack('n', $this->field($handle, 2, $bundle))[1];

            if ($nameLength < 1 || $nameLength > self::MAX_NAME_BYTES) {
                throw BackupRefusedException::notABackup($bundle);
            }

            $name = $this->field($handle, $nameLength, $bundle);

            $this->guardName($name);

            // Two parts with the same name cannot both be written to the same
            // place, and a bundle that asks for it was not written by this
            // build.
            if (isset($seen[$name])) {
                throw BackupRefusedException::notABackup($bundle);
            }

            $seen[$name] = true;

            $partSize = (int) unpack('J', $this->field($handle, 8, $bundle))[1];
            $checksum = bin2hex($this->field($handle, 32, $bundle));

            $parts[] = new BundlePart($name, $partSize, $checksum);
            $manifestBytes += 2 + $nameLength + 8 + 32;
        }

        $declared = 0;

        foreach ($parts as $part) {
            $declared += $part->size;
        }

        // The header cannot ask for more content than the file could possibly
        // hold. Without this a corrupt size field would have the reader run off
        // the end of the file claiming truncation part-way through a part it
        // was never going to reach.
        if ($declared > $size - $fixedBytes - $manifestBytes) {
            throw BackupRefusedException::notABackup($bundle);
        }

        return [$parts, $fixedBytes + $manifestBytes];
    }

    /**
     * Stream one source file into the bundle, re-checking it against the header
     * that was written from it a moment ago.
     *
     * @param  resource  $out
     */
    private function stream(string $source, $out, BundlePart $part): void
    {
        $in = $this->open($source, 'rb');

        try {
            $this->copy($in, $out, $part);
        } finally {
            fclose($in);
        }
    }

    /**
     * Copy exactly the part's length of bytes from one handle to another,
     * hashing as it goes and refusing the part if it does not agree.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    private function copy($in, $out, BundlePart $part): void
    {
        $hash = hash_init('sha256');
        $remaining = $part->size;

        while ($remaining > 0) {
            $chunk = fread($in, min($this->chunkBytes, $remaining));

            if ($chunk === false || $chunk === '') {
                throw BackupRefusedException::partTruncated($part->name, $part->size, $part->size - $remaining);
            }

            hash_update($hash, $chunk);
            $this->put($out, $chunk);

            $remaining -= strlen($chunk);
        }

        $found = hash_final($hash);

        if (! hash_equals($part->checksum, $found)) {
            throw BackupRefusedException::partAltered($part->name, $part->checksum, $found);
        }
    }

    /**
     * Refuse a name that would write outside the folder it is unpacked into.
     *
     * A name is a relative path with `/` separators and nothing else. An
     * absolute name, a Windows drive, a backslash, a null byte or a `.` or `..`
     * segment are all turned away here rather than being normalised and hoped
     * about — this container was written by this build, so a name like that can
     * only mean the file has been tampered with.
     */
    private function guardName(string $name): void
    {
        if ($name === '' || strlen($name) > self::MAX_NAME_BYTES) {
            throw BackupRefusedException::partUnsafe($name);
        }

        if (str_contains($name, "\0") || str_contains($name, '\\') || str_contains($name, ':')) {
            throw BackupRefusedException::partUnsafe($name);
        }

        if (str_starts_with($name, '/')) {
            throw BackupRefusedException::partUnsafe($name);
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw BackupRefusedException::partUnsafe($name);
            }
        }
    }

    /**
     * Where a part lands: the destination directory joined to the part's name.
     */
    private function target(string $directory, string $name): string
    {
        $this->guardName($name);

        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw BackupsException::notStored(basename($directory));
        }
    }

    /**
     * Read exactly this many bytes, or refuse the file.
     *
     * @param  resource  $handle
     */
    private function field($handle, int $length, string $bundle): string
    {
        $bytes = $this->readBytes($handle, $length);

        if (strlen($bytes) !== $length) {
            throw BackupRefusedException::notABackup($bundle);
        }

        return $bytes;
    }

    /**
     * @param  resource  $handle
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
            throw BackupsException::notStored('bundle');
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
