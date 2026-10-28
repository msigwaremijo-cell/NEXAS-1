<?php

namespace App\Domain\Banking\DTOs;

/**
 * A statement file that has been stored, read and understood well enough to
 * build a mapping against.
 *
 * The preview is what the mapping step is for: it shows the column headings the
 * file actually carries and a few movements as they were read, so a person can
 * point each role at a heading they can see rather than guessing at what the
 * bank called things. `line` is the physical file line the movement came from,
 * which means the first movement is line 2 whenever the file has a header.
 *
 * The token is the only handle on the stored file. It is unguessable, it is
 * scoped to one company and one account by where it is stored, and it carries
 * no information about the file's content.
 */
final readonly class StatementUpload
{
    /**
     * @param  list<string>  $headers
     * @param  list<array{line: int, values: array<string, string>}>  $preview
     */
    public function __construct(
        public int $companyId,
        public int $bankAccountId,
        public string $token,
        public string $originalName,
        public int $sizeBytes,
        public string $delimiter,
        public array $headers,
        public array $preview,
        public int $rowCount,
    ) {}
}
