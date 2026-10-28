<?php

namespace App\Domain\Banking\DTOs;

use App\Models\BankStatementLine;

/**
 * The lines one import wrote.
 *
 * Every line in the file is here or the import did not happen at all: a refusal
 * is all of it, because a part-imported statement is worse than a refused one.
 * A reader looking at the line count cannot tell which half of a file landed,
 * and an overlapping re-upload would then duplicate whatever did.
 *
 * `batch` is the upload token, so the lines of one file stay grouped under one
 * name and a later reader can undo an import by batch if it was the wrong file.
 */
final readonly class StatementImportResult
{
    /**
     * @param  list<BankStatementLine>  $lines
     */
    public function __construct(
        public string $batch,
        public int $bankAccountId,
        public array $lines,
    ) {}

    public function imported(): int
    {
        return count($this->lines);
    }
}
