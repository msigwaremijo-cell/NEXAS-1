<?php

namespace App\Domain\Importing\Exceptions;

use App\Domain\Importing\DTOs\ImportRowError;
use RuntimeException;

/**
 * One row's first complaint, carried up to the import loop.
 *
 * A row's checks are written as if each one were fatal, which keeps them flat
 * and readable, and this is what turns the first failure into one entry on the
 * report instead of ending the run. It never leaves the importer: the loop
 * catches it, records the row error and moves to the next row, so one bad row
 * does not hide the twenty after it.
 */
final class ImportRowRefusal extends RuntimeException
{
    public function __construct(public readonly ImportRowError $error)
    {
        parent::__construct((string) $error);
    }

    public static function of(ImportRowError $error): self
    {
        return new self($error);
    }
}
