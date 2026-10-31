<?php

namespace App\Domain\Reporting\Exports;

use Closure;

/**
 * One report the export pipeline knows how to render.
 *
 * A definition is the three things a report needs to become a file: what it is
 * called, which right guards it, and how to build it. The build is a closure
 * rather than a method name because it is the one part the registry cannot
 * share between reports: each report reads a different query service and
 * flattens a different payload into a document.
 *
 * `parameters` is the window the report accepts — `from`/`to` for a period
 * report, `as_of` for a standing one, `compare` for a prior column. The
 * controller filters the caller's request through it, so a report is only ever
 * asked for the answers it gives.
 */
final readonly class ReportDefinition
{
    /**
     * @param  list<string>  $parameters
     * @param  Closure(int, array<string, mixed>): ReportDocument  $build
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $permission,
        public array $parameters,
        public Closure $build,
    ) {}
}
