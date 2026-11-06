<?php

/**
 * Writes the synthetic DTR sample files and their expected results.
 *
 *   php docs/09-testing/dtr-samples/generate_dtr_samples.php
 *
 * Every person, number and time here is invented. The workers are the ones
 * DtrSampleSeeder creates (biometrics 90001-90005). The expected results are
 * written out by hand from the documented comparison rules
 * (docs/07-project-management/dtr-attendance-rework.md §3) and are never
 * computed with the code under test. tests/Feature/DtrSampleFilesTest.php
 * imports these files and compares the system's answers against them.
 */

require __DIR__.'/../../../backend/laravel/vendor/autoload.php';

use Database\Seeders\DtrSampleSeeder;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$out = __DIR__;
$header = ['Biometric No.', 'Employee Name', 'Date', 'Time In', 'Time Out', 'Remarks'];
$name = fn (string $bio) => strtoupper(DtrSampleSeeder::EMPLOYEES[$bio]['last'].', '.DtrSampleSeeder::EMPLOYEES[$bio]['first']);
$scheduleText = [
    'day' => 'Mon-Fri 08:00-17:00 (break 12:00, 60 min)',
    'night' => 'Mon-Fri 22:00-06:00 next day',
    'six_day' => 'Mon-Sat 07:00-16:00 (break 11:00, 60 min)',
];

/*
 * The test week: Monday 19 to Sunday 25 October 2026, imported as one file
 * covering that period. Per worker and day: the times in the file (null = no
 * row at all), then what the comparison must say once HR has confirmed the
 * client's DTR for the week complete (DTR periods, 4 November 2026).
 *
 * [time_in, time_out, status, discrepancies, late, early, review, scenario]
 */
$week = [
    '90001' => [ // Alma Dizon - day shift Mon-Fri 08:00-17:00
        '2026-10-19' => ['07:52', '17:03', 'on_schedule', [], 0, 0, '', 'A. Normal - arrives early, leaves 3 min after the end'],
        '2026-10-20' => ['08:00', '17:00', 'on_schedule', [], 0, 0, '', 'A. Normal - exactly on time'],
        '2026-10-21' => ['08:17', '17:00', 'discrepancy', ['late'], 17, 0, 'for_review', 'B. Late arrival, 17 min (grace 0)'],
        '2026-10-22' => ['08:00', '16:30', 'discrepancy', ['early_departure'], 0, 30, 'for_review', 'C. Early departure, 30 min'],
        '2026-10-23' => ['', '17:00', 'discrepancy', ['missing_time_in'], 0, 0, 'for_review', 'D. Missing time-in'],
        '2026-10-24' => ['09:00', '13:00', 'discrepancy', ['outside_schedule'], 0, 0, 'for_review', 'G. Times on a rest day (Saturday)'],
        '2026-10-25' => [null, null, 'rest_day', [], 0, 0, '', 'Rest day, no row'],
    ],
    '90002' => [ // Benjie Ocampo - night shift Mon-Fri 22:00-06:00
        '2026-10-19' => ['21:55', '06:02', 'on_schedule', [], 0, 0, '', 'H. Overnight shift, time-out next morning'],
        '2026-10-20' => ['22:12', '06:00', 'discrepancy', ['late'], 12, 0, 'for_review', 'H+B. Overnight shift, late 12 min'],
        '2026-10-21' => ['22:00', '', 'discrepancy', ['missing_time_out'], 0, 0, 'for_review', 'E. Missing time-out'],
        '2026-10-22' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. No row on a workday. Awaiting DTR after the import; No DTR record (not an absence) once HR confirms the period complete'],
        '2026-10-23' => ['22:00', '05:30', 'discrepancy', ['early_departure'], 0, 30, 'for_review', 'H+C. Overnight shift, left 30 min early'],
        '2026-10-24' => [null, null, 'rest_day', [], 0, 0, '', 'Rest day, no row'],
        '2026-10-25' => [null, null, 'rest_day', [], 0, 0, '', 'Rest day, no row'],
    ],
    '90003' => [ // Carla Mendoza - Mon-Sat 07:00-16:00
        '2026-10-19' => ['06:58', '16:00', 'on_schedule', [], 0, 0, '', 'A. Normal'],
        '2026-10-20' => ['07:01', '16:00', 'discrepancy', ['late'], 1, 0, 'for_review', 'B. Late by 1 min - flagged because the grace period is 0'],
        '2026-10-21' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. No row on a workday. Awaiting DTR until HR confirms the period complete'],
        '2026-10-22' => ['07:00', '16:05', 'on_schedule', [], 0, 0, '', 'A. Normal - 5 min after the end is information only'],
        '2026-10-23' => ['07:00', '16:00', 'on_schedule', [], 0, 0, '', 'A. Normal'],
        '2026-10-24' => ['07:00', '16:00', 'on_schedule', [], 0, 0, '', 'A. Saturday is a workday on her schedule'],
        '2026-10-25' => [null, null, 'rest_day', [], 0, 0, '', 'Rest day, no row'],
    ],
    '90004' => [ // Dario Villanueva - day shift, no rows in the file at all
        '2026-10-19' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. Not in the file: Awaiting DTR after the import; No DTR record to verify (never an absence) once HR confirms the period complete'],
        '2026-10-20' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. Not in the file, period confirmed complete'],
        '2026-10-21' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. Not in the file, period confirmed complete'],
        '2026-10-22' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. Not in the file, period confirmed complete'],
        '2026-10-23' => [null, null, 'discrepancy', ['no_record'], 0, 0, 'for_review', 'F. Not in the file, period confirmed complete'],
        '2026-10-24' => [null, null, 'rest_day', [], 0, 0, '', 'Rest day'],
        '2026-10-25' => [null, null, 'rest_day', [], 0, 0, '', 'Rest day'],
    ],
];

/*
 * The same days straight after the import, before HR has confirmed the period:
 * an import alone never says the DTR is complete, so every scheduled day with
 * no row is only Awaiting DTR. Days not listed read as above.
 */
$beforeConfirmation = [
    '90002' => ['2026-10-22'],
    '90003' => ['2026-10-21'],
    '90004' => ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'],
];

/** The weekly figures each worker's DTR tab and the workforce summary must show, once the period is confirmed. */
$summaries = [
    // bio => scheduled, compared, on_schedule, discrepancy, late d/min, early d/min, missing punch, no record, outside schedule, awaiting, after end, records, data_status
    '90001' => [5, 5, 2, 4, 1, 17, 1, 30, 1, 0, 1, 0, 3, 6, 'complete'],
    '90002' => [5, 5, 1, 4, 1, 12, 1, 30, 1, 1, 0, 0, 2, 4, 'complete'],
    '90003' => [6, 6, 4, 2, 1, 1, 0, 0, 0, 1, 0, 0, 5, 5, 'complete'],
    '90004' => [5, 5, 0, 5, 0, 0, 0, 0, 0, 5, 0, 0, 0, 0, 'complete'],
];
$summaryColumns = [
    'scheduled_days', 'compared_days', 'on_schedule_days', 'discrepancy_days', 'late_days', 'late_minutes',
    'early_departure_days', 'early_departure_minutes', 'missing_punch_days', 'no_record_days',
    'outside_schedule_days', 'awaiting_dtr_days', 'minutes_after_end', 'records', 'data_status',
];

// --------------------------------------------------------------- valid file

$valid = [];
foreach ($week as $bio => $days) {
    foreach ($days as $date => [$in, $outTime, , , , , , $scenario]) {
        if ($in !== null) {
            $valid[] = [$bio, $name($bio), $date, $in, $outTime, 'TEST: '.$scenario];
        }
    }
}

// ------------------------------------------------------------- invalid file
// Every row has a different fault; none can be imported.
// [cells, expected row status, why]
$invalid = [
    [['90001', $name('90001'), '19/10/2026', '08:00', '17:00', 'TEST: day-first date'], 'invalid_date', 'Date not in the chosen format (Y-m-d)'],
    [['90001', $name('90001'), '2026-10-20', '8:75', '17:00', 'TEST: minutes 75'], 'invalid_time', 'Time-in 8:75 cannot be read'],
    [['90003', $name('90003'), '2026-10-20', '07:00', '25:30', 'TEST: hour 25'], 'invalid_time', 'Time-out 25:30 cannot be read'],
    [['', 'NO IDENTIFIER', '2026-10-21', '08:00', '17:00', 'TEST: blank biometrics no.'], 'missing_identifier', 'No employee identifier'],
    [['99999', 'GHOST, NOBODY', '2026-10-21', '08:00', '17:00', 'TEST: unknown number'], 'unmatched_employee', 'No employee has biometrics no. 99999'],
    [['90002', $name('90002'), '', '22:00', '06:00', 'TEST: blank date'], 'missing_date', 'Required date missing'],
    [['90003', $name('90003'), '2026-10-22', '', '', 'TEST: no times'], 'blank_times', 'Neither time-in nor time-out (skipped, not an error)'],
    [['90001', 'REYES, JUAN', '2026-10-22', '08:00', '17:00', 'TEST: wrong name'], 'name_mismatch', 'Name does not match biometrics no. 90001'],
    [['90002', $name('90002'), '2026-10-30', '22:00', '06:00', 'TEST: after the period'], 'outside_period', 'Date is after the file\'s period (19-25 Oct)'],
    [['90004', $name('90004'), '2026-10-21', '2026-10-21 17:00', '2026-10-21 08:00', 'TEST: out before in'], 'invalid_time', 'Time-out is before time-in on the same date'],
    [['90005', $name('90005'), '2026-10-21', '08:00', '17:00', 'TEST: other client'], 'not_at_client', 'Employee exists but is placed at another client'],
    [['*** END OF REPORT ***'], 'unmatched_employee', 'Malformed footer row: one cell of text, no date or times'],
];

// ----------------------------------------------------------- duplicate file
// Covers 19 Oct - 1 Nov, after the valid file has been imported.
$duplicate = [
    [['90001', $name('90001'), '2026-10-19', '07:52', '17:03', 'TEST: same as on file'], 'unchanged', 'Identical to the day already imported - nothing written'],
    [['90001', $name('90001'), '2026-10-20', '08:05', '17:00', 'TEST: differs from file'], 'conflict', 'Differs from the DTR on file (08:00) - kept as on file unless HR chooses Replace with a reason'],
    [['90001', $name('90001'), '2026-10-26', '08:00', '17:00', 'TEST: new day'], 'new', 'Imported'],
    [['90003', $name('90003'), '2026-10-26', '07:00', '16:00', 'TEST: listed twice, same times'], 'new', 'Imported once'],
    [['90003', $name('90003'), '2026-10-26', '07:00', '16:00', 'TEST: listed twice, same times'], 'duplicate_in_file', 'Exact repeat of the row above - skipped'],
    [['90003', $name('90003'), '2026-10-27', '07:00', '16:00', 'TEST: listed twice, different times'], 'conflicting_in_file', 'Same day twice with different times - neither imported'],
    [['90003', $name('90003'), '2026-10-27', '07:30', '16:00', 'TEST: listed twice, different times'], 'conflicting_in_file', 'Same day twice with different times - neither imported'],
];

// ------------------------------------------------------------------ writing

$csv = function (string $file, array $rows) use ($out, $header) {
    $h = fopen("{$out}/{$file}", 'w');
    fputcsv($h, $header, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($h, $row, ',', '"', '');
    }
    fclose($h);
};

$csv('EMPOWER_DTR_Valid_Test_Data.csv', $valid);
$csv('EMPOWER_DTR_Invalid_Test_Data.csv', array_column($invalid, 0));
$csv('EMPOWER_DTR_Duplicate_Test_Data.csv', array_column($duplicate, 0));

// The valid file again as a workbook, with real date and time cells as a
// timekeeping export would have them.
$book = new Spreadsheet();
$sheet = $book->getActiveSheet()->setTitle('DTR');
$sheet->fromArray($header, null, 'A1');
foreach ($valid as $i => [$bio, $who, $date, $in, $outTime, $remarks]) {
    $r = $i + 2;
    $sheet->setCellValueExplicit("A{$r}", $bio, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue("B{$r}", $who);
    $sheet->setCellValue("C{$r}", ExcelDate::PHPToExcel(new DateTimeImmutable($date)));
    $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    foreach (['D' => $in, 'E' => $outTime] as $col => $time) {
        if ($time !== '') {
            [$hh, $mm] = array_map('intval', explode(':', $time));
            $sheet->setCellValue("{$col}{$r}", ($hh * 60 + $mm) / 1440);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode('hh:mm');
        }
    }
    $sheet->setCellValue("F{$r}", $remarks);
}
foreach (range('A', 'F') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
(new Xlsx($book))->save("{$out}/EMPOWER_DTR_Valid_Test_Data.xlsx");

// Expected day-by-day results for the week.
$h = fopen("{$out}/EMPOWER_DTR_Expected_Day_Results.csv", 'w');
fputcsv($h, ['biometric_no', 'employee', 'date', 'weekday', 'approved_schedule', 'row_in_file', 'time_in', 'time_out',
    'expected_status', 'expected_discrepancies', 'late_minutes', 'early_departure_minutes', 'expected_review_status',
    'status_before_period_confirmed', 'scenario'], ',', '"', '');
foreach ($week as $bio => $days) {
    $person = DtrSampleSeeder::EMPLOYEES[$bio];
    foreach ($days as $date => [$in, $outTime, $status, $flags, $late, $early, $review, $scenario]) {
        fputcsv($h, [$bio, "{$person['first']} {$person['last']}", $date, date('D', strtotime($date)), $scheduleText[$person['schedule']],
            $in === null ? 'no' : 'yes', $in ?? '', $outTime ?? '', $status, implode(';', $flags), $late, $early, $review,
            in_array($date, $beforeConfirmation[$bio] ?? [], true) ? 'awaiting_dtr' : $status, $scenario], ',', '"', '');
    }
}
fclose($h);

// Expected per-row outcome of every file at preview.
$h = fopen("{$out}/EMPOWER_DTR_Expected_Row_Outcomes.csv", 'w');
fputcsv($h, ['file', 'line', 'biometric_no', 'date', 'expected_row_status', 'why'], ',', '"', '');
foreach ($valid as $i => $row) {
    fputcsv($h, ['EMPOWER_DTR_Valid_Test_Data.csv', $i + 2, $row[0], $row[2], 'new', 'Ready to import'], ',', '"', '');
}
foreach (['EMPOWER_DTR_Invalid_Test_Data.csv' => $invalid, 'EMPOWER_DTR_Duplicate_Test_Data.csv' => $duplicate] as $file => $rows) {
    foreach ($rows as $i => [$cells, $status, $why]) {
        fputcsv($h, [$file, $i + 2, $cells[0] ?? '', $cells[2] ?? '', $status, $why], ',', '"', '');
    }
}
fclose($h);

// Expected weekly summary per worker.
$h = fopen("{$out}/EMPOWER_DTR_Expected_Summary.csv", 'w');
fputcsv($h, array_merge(['biometric_no', 'employee'], $summaryColumns), ',', '"', '');
foreach ($summaries as $bio => $values) {
    $person = DtrSampleSeeder::EMPLOYEES[$bio];
    fputcsv($h, array_merge([$bio, "{$person['first']} {$person['last']}"], $values), ',', '"', '');
}
fclose($h);

echo "Written to {$out}\n";
