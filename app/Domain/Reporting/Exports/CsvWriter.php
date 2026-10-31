<?php

namespace App\Domain\Reporting\Exports;

/**
 * A ReportDocument written as CSV.
 *
 * Plain values only. A figure is written as the ledger's own decimal
 * (`12000.0000`), not as a currency-formatted string, because the caller that
 * asked for a CSV is the caller that will decide how to present it — and a
 * spreadsheet that receives `TSh 12,000.00` receives text where it wanted a
 * number it could add up.
 *
 * The layout is the document's: a title line, the header block, then each
 * section under its own heading with its rows and its total, then the report's
 * summary figures, then any note. It is a report a person reads rather than a
 * strict interchange format, and it is the same report the PDF shows.
 */
final class CsvWriter
{
    public function render(ReportDocument $document): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            // php://temp is always openable; this is unreachable, but a writer
            // that returned a blank file on a bad handle would be a silently
            // wrong export rather than a loud one.
            throw new \RuntimeException('Unable to open a temporary stream to write the CSV export.');
        }

        $this->line($handle, [$document->title]);

        foreach ($document->meta as $line) {
            $this->line($handle, [$line['label'], $line['value']]);
        }

        foreach ($document->sections as $section) {
            $this->line($handle, []);
            $this->line($handle, [$section->heading]);

            if ($section->columns !== []) {
                $this->line($handle, $section->columns);
            }

            foreach ($section->rows as $row) {
                $this->line($handle, $row);
            }

            if ($section->total !== null) {
                $this->line($handle, $section->total);
            }
        }

        if ($document->summary !== []) {
            $this->line($handle, []);
            $this->line($handle, ['Summary']);

            foreach ($document->summary as $line) {
                $this->line($handle, [$line['label'], $line['value']]);
            }
        }

        if ($document->note !== null && $document->note !== '') {
            $this->line($handle, []);
            $this->line($handle, [$document->note]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $cells
     */
    private function line($handle, array $cells): void
    {
        // The escape character is passed explicitly: its default is deprecated
        // as of PHP 8.4, and a report of account names should not be the place
        // a deprecation notice appears.
        fputcsv($handle, $cells, ',', '"', '');
    }
}
