<?php

namespace App\Domain\Reporting\Exports;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A ReportDocument rendered as a PDF.
 *
 * The page is laid out by a Blade view, which is escaped output only: a PDF is
 * built from the company's own account names and references, and those are
 * data, not markup. Nothing here loads a remote image or a remote stylesheet —
 * config/dompdf.php turns remote access off, so a report cannot be made to
 * fetch anything from outside the server no matter what an account is named.
 *
 * The document is passed through whole, so the view and the CSV writer read
 * one shape and a report cannot look like one thing on screen and another in
 * the file.
 */
final class PdfWriter
{
    public function render(ReportDocument $document): string
    {
        $html = view('exports.report', ['document' => $document])->render();

        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }
}
