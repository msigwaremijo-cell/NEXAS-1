<?php

namespace App\Domain\Backups\DTOs;

/**
 * One named part inside a backup bundle, as the bundle's own header records it.
 *
 * The name is a path relative to the bundle — `database.sql`, or
 * `attachments/7/01H....pdf` — and it is the only thing that decides where the
 * part lands when the bundle is unpacked. The checksum is taken over the part's
 * bytes at the moment the bundle was written, so an unpack can tell that one
 * part of a supposedly whole file has been changed without having to trust the
 * rest of it.
 *
 * Both the size and the checksum are recorded before the content is written,
 * which is what lets the reader walk the file forwards: it knows how much of
 * each part to expect and what it should hash to, rather than trusting a
 * length prefix it has not verified.
 */
final class BundlePart
{
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        public readonly string $checksum,
    ) {}
}
