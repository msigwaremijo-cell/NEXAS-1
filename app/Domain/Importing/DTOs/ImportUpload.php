<?php

namespace App\Domain\Importing\DTOs;

use App\Domain\Importing\Enums\ImportDataset;

/**
 * A stored import file, and enough of what is inside it to build a mapping
 * against.
 *
 * The file hash travels here as well as being recomputed when the file is run:
 * showing it to the operator is what makes "you already imported this file"
 * something they can check for themselves rather than take on trust.
 */
final readonly class ImportUpload
{
    /**
     * @param  list<string>  $headers
     * @param  list<array{line: int, values: array<string, string>}>  $preview
     */
    public function __construct(
        public int $companyId,
        public ImportDataset $dataset,
        public string $token,
        public string $originalName,
        public int $sizeBytes,
        public string $fileHash,
        public string $delimiter,
        public array $headers,
        public array $preview,
        public int $rowCount,
    ) {}
}
