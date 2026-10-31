<?php

namespace App\Domain\Reporting\Exports;

/**
 * A report rendered into the shape both writers can print.
 *
 * This is the seam the export pipeline is built on. A report definition turns
 * a report's own answer — whatever nested array the query service returns —
 * into one of these, and from that point on the two writers are identical for
 * every report: CSV writes the sections out as rows, PDF lays the same
 * sections out on a page. Adding a report family therefore means adding a
 * definition that produces a document, and neither writer changes.
 *
 * `meta` is the header block: who and what and over which period. `summary` is
 * the figures that belong to the whole report rather than to one of its
 * sections — a net profit, whether a statement balances — and it is kept
 * apart from the sections because those two facts are not rows of any table
 * above them.
 */
final readonly class ReportDocument
{
    /**
     * @param  list<array{label: string, value: string}>  $meta
     * @param  list<ReportSection>  $sections
     * @param  list<array{label: string, value: string}>  $summary
     */
    public function __construct(
        public string $title,
        public array $meta,
        public array $sections,
        public array $summary = [],
        public ?string $note = null,
    ) {}
}
