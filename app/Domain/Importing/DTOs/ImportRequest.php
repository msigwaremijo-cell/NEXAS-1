<?php

namespace App\Domain\Importing\DTOs;

use App\Domain\Importing\Enums\ImportDataset;

/**
 * Everything one run of an import needs.
 *
 * `mapping` is field => the heading of the column that carries it, named by the
 * caller rather than guessed: a chart of accounts exported from one system
 * calls its account code column "Code" and another calls it "Account No", and
 * nothing in the file settles which it is. The mapping is validated against the
 * file before a single row is read, so a mapping that names a column the file
 * does not carry is refused with the file's real headings in the message.
 *
 * `dryRun` is the whole difference between looking and writing. A dry run reads
 * the file, checks every row and reports what it found without touching the
 * books; a commit re-checks the same rows inside one transaction and writes
 * them all or none of them.
 *
 * `asOf` is the date the opening figures are as at, and is only meaningful for
 * the two opening datasets — the layers opening stock creates are dated when
 * they were counted, and the single opening journal is dated when the books
 * begin.
 *
 * `fileName` is what the file was called when it was uploaded. It is recorded
 * on the run rather than used to read anything, and it exists so that a refusal
 * that says "you have already imported this file" can name the file it means.
 */
final readonly class ImportRequest
{
    /**
     * @param  array<string, string>  $mapping
     */
    public function __construct(
        public int $companyId,
        public ImportDataset $dataset,
        public string $uploadToken,
        public array $mapping,
        public bool $dryRun = false,
        public ?string $asOf = null,
        public ?string $fileName = null,
        public ?int $userId = null,
    ) {}

    public function column(string $field): ?string
    {
        $column = $this->mapping[$field] ?? null;

        return is_string($column) && $column !== '' ? $column : null;
    }

    public function withDryRun(bool $dryRun): self
    {
        return new self(
            companyId: $this->companyId,
            dataset: $this->dataset,
            uploadToken: $this->uploadToken,
            mapping: $this->mapping,
            dryRun: $dryRun,
            asOf: $this->asOf,
            fileName: $this->fileName,
            userId: $this->userId,
        );
    }
}
