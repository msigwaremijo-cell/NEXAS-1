<?php

namespace App\Domain\Reporting\Enums;

/**
 * The two file kinds a report is rendered as.
 *
 * CSV is the one a reader opens in a spreadsheet or feeds to another system,
 * so it is written as plain values with no formatting applied: a figure is the
 * ledger's own decimal, not a currency-styled string, because the caller that
 * asked for it is the caller that will decide how to present it. PDF is the one
 * a reader prints or files, so it is the presentation: the same figures laid
 * out under headings with a company header.
 */
enum ExportFormat: string
{
    case Csv = 'csv';

    case Pdf = 'pdf';

    public function extension(): string
    {
        return $this->value;
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Pdf => 'application/pdf',
        };
    }
}
