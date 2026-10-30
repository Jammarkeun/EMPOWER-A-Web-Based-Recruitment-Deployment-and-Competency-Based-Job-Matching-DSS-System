<?php

namespace App\Services\Attendance;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Turns a DTR file into rows of text, and nothing more.
 *
 * CDE has not supplied a sample of the client's biometric export, so this
 * deliberately knows no column layout. It reads a CSV (comma, semicolon, tab
 * or pipe separated) or the first sheet of an Excel workbook, as the values
 * appear on screen, and leaves the meaning of each column to the mapping HR
 * chooses on the import screen.
 */
final class DtrFileReader
{
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * Non-empty rows, keyed by their row number in the file (the first row is
     * 1), so a problem can be pointed out where HR will find it.
     *
     * @return array<int, list<string>>
     */
    public function read(string $contents, string $extension, int $maxRows): array
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Only CSV and Excel (.xlsx, .xls) files can be imported.', 422);
        }

        $raw = in_array($extension, ['csv', 'txt'], true)
            ? $this->csv($contents)
            : $this->spreadsheet($contents, $extension);

        // Empty rows are spreadsheet furniture.
        $rows = [];

        foreach (array_values($raw) as $i => $row) {
            $cells = array_map(fn ($cell) => trim((string) $cell), $row);

            if (implode('', $cells) !== '') {
                $rows[$i + 1] = $cells;
            }
        }

        if ($rows === []) {
            throw new RuntimeException('The file has no rows to read.', 422);
        }

        if (count($rows) > $maxRows + 1) {
            throw new RuntimeException(sprintf(
                'The file has %d rows; at most %d can be imported at once. Split it by period and import each part.',
                count($rows) - 1,
                $maxRows
            ), 422);
        }

        return $rows;
    }

    private function csv(string $contents): array
    {
        // A UTF-8 byte-order mark, as Excel writes one, is not part of the
        // first header.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        // Files saved by older Windows software are often not UTF-8.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t", '|'])
            ->sortByDesc(fn ($d) => substr_count($firstLine, $d))
            ->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                $rows[] = $row;
            }
        }

        fclose($stream);

        return $rows;
    }

    private function spreadsheet(string $contents, string $extension): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dtr');
        file_put_contents($path, $contents);

        try {
            $reader = IOFactory::createReader($extension === 'xls' ? 'Xls' : 'Xlsx');

            // Values as formatted in the sheet - "9/7/2026", "8:05 AM" - since
            // that is what HR sees and maps a date format against.
            return $reader->load($path)->getSheet(0)->toArray(null, true, true, false);
        } catch (Throwable $e) {
            throw new RuntimeException('The spreadsheet could not be read. Save it again as .xlsx or .csv and try again.', 422, $e);
        } finally {
            @unlink($path);
        }
    }
}
