<?php

namespace App\Domain\Reporting\Exceptions;

use App\Domain\Accounting\Exceptions\AccountingException;

/**
 * A render asked for a report the pipeline does not know.
 *
 * The route validates the type against the registry before a record is written,
 * so this is not reachable through the API. It is raised anyway, by the
 * registry itself, because the job can be handed a stored type that no longer
 * exists — a report removed between the request and the worker — and the one
 * thing that must not happen then is a file written from an unknown report.
 * The job turns it into a failed record with this message.
 */
final class UnknownExportTypeException extends AccountingException
{
    public static function named(string $type): self
    {
        return new self(sprintf(
            'There is no report export of type "%s". Ask for one the pipeline knows.',
            $type
        ));
    }
}
