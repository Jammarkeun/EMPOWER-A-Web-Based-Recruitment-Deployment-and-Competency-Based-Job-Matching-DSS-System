<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Renders any report from ReportBuilder as a formatted spreadsheet.
 *
 * The point of the Excel export is that HR can carry on working with the data —
 * sort it, filter it, pivot it — so the sheet is built with a frozen header row
 * and auto-filters rather than as a static picture of the PDF.
 */
class ReportExport implements FromArray, WithHeadings, WithTitle, ShouldAutoSize, WithEvents
{
    public function __construct(private readonly array $report)
    {
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
                $columnCount = count($this->report['columns']);
                $lastColumn = $sheet->getCellByColumnAndRow($columnCount, 1)->getColumn();
                $rowCount = count($this->report['rows']) + 1;

                $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B4B8F']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(22);

                // Freeze and filter, so a long masterlist stays workable.
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}{$rowCount}");

                if ($rowCount > 1) {
                    $sheet->getStyle("A2:{$lastColumn}{$rowCount}")->applyFromArray([
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'E4EAF4'],
                            ],
                        ],
                        'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
                    ]);
                }

                // The summary is appended below the data rather than placed on a
                // second sheet, so it survives being copied elsewhere.
                $summaryRow = $rowCount + 2;
                $sheet->setCellValue("A{$summaryRow}", 'SUMMARY');
                $sheet->getStyle("A{$summaryRow}")->getFont()->setBold(true)->setSize(10);

                foreach ($this->report['summary'] as $index => $item) {
                    $row = $summaryRow + 1 + $index;
                    $sheet->setCellValue("A{$row}", $item['label']);
                    $sheet->setCellValue("B{$row}", $item['value']);
                    $sheet->getStyle("B{$row}")->getFont()->setBold(true);
                }

                $footerRow = $summaryRow + count($this->report['summary']) + 2;
                $sheet->setCellValue("A{$footerRow}", 'Generated from EMPOWER on '.$this->report['generated_at']);
                $sheet->setCellValue("A".($footerRow + 1), 'Confidential — contains personal information protected under RA 10173.');
                $sheet->getStyle("A{$footerRow}:A".($footerRow + 1))
                    ->getFont()->setItalic(true)->setSize(8)
                    ->getColor()->setRGB('7B8BA3');
            },
        ];
    }
}
