<?php

namespace App\Domain\Files\DTOs;

/**
 * A file the shared validator has accepted, described well enough to store it.
 *
 * `storedName` is a fresh ULID with the accepted extension, and the path is
 * built from the company id and that name alone. Neither the name the caller
 * gave the file nor any directory they put in front of it reaches the store,
 * so there is no point at which caller text becomes part of a path and no way
 * for an upload to be filed outside its own company's folder.
 *
 * `originalName` is kept, stripped of any directory, because that is the name
 * a human recognises and the name a download should carry back.
 */
final class ValidatedUpload
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly string $checksum,
        public readonly string $storedName,
    ) {}

    /**
     * Where the file lives inside whichever private disk it is written to.
     */
    public function pathFor(int $companyId): string
    {
        return $companyId.'/'.$this->storedName;
    }
}
