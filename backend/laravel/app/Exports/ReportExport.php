<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Renders any report from ReportBuilder as a formatted spreadsheet.
 *
 * The top of the sheet follows CDE's own Manpower Requisition Form, like the
 * PDF: a ruled box with the logo, the agency name and address and the report's
 * title in capitals, then the report's details as "Label: value". Below that
 * it stays a working sheet - HR carries on sorting, filtering and pivoting the
 * data - so the heading row is frozen and filtered, and the letterhead repeats
 * at the top of every printed page.
 */
class ReportExport extends DefaultValueBinder implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithEvents, WithCustomValueBinder, WithCustomStartCell
{
    /** Rows above the table: the letterhead (1-3) and the report's details (4-5). */
    private const HEADING_ROW = 6;

    public function __construct(
        private readonly array $report,
        private readonly ?string $preparedBy = null,
    ) {
    }

    /**
     * Text is written as text, never as a formula.
     *
     * The default binder turns any string beginning with "=" into a formula, so
     * a name an applicant typed as "=HYPERLINK(...)" ran when HR opened the
     * file. Text that could start a formula is stored as an explicit string
     * cell; numbers and everything else are bound as before.
     */
    public function bindValue(Cell $cell, $value)
    {
        if (SpreadsheetSafety::isDangerous($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function startCell(): string
    {
        return 'A'.self::HEADING_ROW;
    }

    public function array(): array
    {
        return array_map(
            fn ($row) => is_array($row) ? $row : (array) $row,
            $this->report['rows']->all()
        );
    }

    public function headings(): array
    {
        return $this->report['columns'];
    }

    public function title(): string
    {
        // Excel rejects sheet names over 31 characters or containing / \ ? * : [ ]
        return mb_substr(preg_replace('/[\/\\\\?*:\[\]]/', '', $this->report['title']), 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                // At least four columns, so the letterhead has room on a narrow report.
                $lastColumn = Coordinate::stringFromColumnIndex(max(count($this->report['columns']), 4));
                $headingRow = self::HEADING_ROW;
                $lastDataRow = $headingRow + count($this->report['rows']);

                $this->letterhead($sheet, $lastColumn);

                $sheet->getStyle("A{$headingRow}:{$lastColumn}{$headingRow}")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B4B8F']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                ]);
                $sheet->getRowDimension($headingRow)->setRowHeight(22);

                // Freeze and filter, so a long masterlist stays workable.
                $sheet->freezePane('A'.($headingRow + 1));
                $sheet->setAutoFilter("A{$headingRow}:{$lastColumn}{$lastDataRow}");

                if ($lastDataRow > $headingRow) {
                    // Ruled like the paper form, so a printed sheet reads as one.
                    $sheet->getStyle('A'.($headingRow + 1).":{$lastColumn}{$lastDataRow}")->applyFromArray([
                        'borders' => [
                            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '555555']],
                        ],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                    ]);
                }

                // The summary is appended below the data rather than placed on a
                // second sheet, so it survives being copied elsewhere.
                $summaryRow = $lastDataRow + 2;
                $sheet->setCellValue("A{$summaryRow}", 'Summary');
                $sheet->getStyle("A{$summaryRow}")->getFont()->setBold(true)->setSize(10);

                foreach ($this->report['summary'] as $index => $item) {
                    $row = $summaryRow + 1 + $index;
                    $sheet->setCellValue("A{$row}", $item['label']);
                    $sheet->setCellValue("B{$row}", $item['value']);
                    $sheet->getStyle("B{$row}")->applyFromArray([
                        'font' => ['bold' => true],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
                        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
                    ]);
                }

                $signatureRow = $summaryRow + count($this->report['summary']) + 2;
                $this->signatures($sheet, $signatureRow);

                // Merged across the sheet, so these long lines do not set the
                // width of the first data column.
                $footerRow = $signatureRow + 7;
                $sheet->setCellValue("A{$footerRow}", 'Generated from EMPOWER on '.$this->report['generated_at']);
                $sheet->setCellValue('A'.($footerRow + 1), 'Confidential — contains personal information protected under RA 10173.');
                $sheet->mergeCells("A{$footerRow}:{$lastColumn}{$footerRow}");
                $sheet->mergeCells('A'.($footerRow + 1).":{$lastColumn}".($footerRow + 1));
                $sheet->getStyle("A{$footerRow}:A".($footerRow + 1))
                    ->getFont()->setItalic(true)->setSize(8)
                    ->getColor()->setRGB('555555');

                // Printed, it keeps the PDF's shape: landscape, one page wide,
                // the letterhead and the heading row on every page.
                $sheet->getPageSetup()
                    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
                    ->setPaperSize(PageSetup::PAPERSIZE_A4)
                    ->setFitToWidth(1)
                    ->setFitToHeight(0)
                    ->setRowsToRepeatAtTopByStartAndEnd(1, $headingRow);
            },
        ];
    }

    /**
     * Rows 1-5: CDE's letterhead in a ruled box - logo, agency, address and the
     * title in capitals - then the report's details as "Label: value" lines.
     */
    private function letterhead(Worksheet $sheet, string $lastColumn): void
    {
        $agency = (string) config('empower.organisation.name');

        $lines = [
            1 => [$agency, 13, false, 21],
            2 => [(string) config('empower.organisation.address'), 10, false, 16],
            3 => [mb_strtoupper($this->report['title']), 12, true, 24],
        ];

        foreach ($lines as $row => [$text, $size, $bold, $height]) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->setCellValue("A{$row}", $text);
            $sheet->getStyle("A{$row}")->getFont()->setSize($size)->setBold($bold);
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        $details = [
            4 => 'Report: '.$this->report['subtitle'],
            5 => implode('        ', array_filter([
                'Period: '.$this->report['period'],
                'Number of Records: '.number_format(count($this->report['rows'])),
                'Date generated: '.$this->report['generated_at'],
                $this->preparedBy ? 'Prepared by: '.$this->preparedBy : null,
            ])),
        ];

        foreach ($details as $row => $text) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->setCellValue("A{$row}", $text);
            $sheet->getStyle("A{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setIndent(1);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $box = ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '000000']]];
        $sheet->getStyle("A1:{$lastColumn}3")->applyFromArray(['borders' => $box]);
        $sheet->getStyle("A4:{$lastColumn}5")->applyFromArray(['borders' => $box]);
        $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ]);

        // A missing logo must never be the reason a report fails to generate.
        $logo = public_path('logo/cde-manpower.png');
        if (is_file($logo)) {
            $drawing = new Drawing();
            $drawing->setName('CDE Manpower Services');
            $drawing->setPath($logo);
            $drawing->setHeight(70);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(6);
            $drawing->setOffsetY(4);
            $drawing->setWorksheet($sheet);
        }
    }

    /** The PDF's signature lines: the label, the name over a line, the agency beneath. */
    private function signatures(Worksheet $sheet, int $row): void
    {
        $agency = (string) config('empower.organisation.name');
        $line = ['borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]]];

        foreach (['Prepared by:' => $this->preparedBy, 'Reviewed by:' => null, 'Approved by:' => null] as $label => $name) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $name ? mb_strtoupper($name) : '');
            $sheet->getStyle("B{$row}")->applyFromArray($line + [
                'font' => ['bold' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->setCellValue('B'.($row + 1), $agency);
            $sheet->getStyle('B'.($row + 1))->applyFromArray([
                'font' => ['size' => 8],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(26);
            $sheet->getStyle("A{$row}:B{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_BOTTOM);
            $row += 2;
        }
    }
}
