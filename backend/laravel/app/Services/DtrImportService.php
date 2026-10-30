<?php

namespace App\Services;

use App\Models\AttendanceCoveragePeriod;
use App\Models\AttendanceImport;
use App\Models\AttendanceRecord;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceContext;
use App\Services\Attendance\DtrFileReader;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Brings a client's DTR file into the time records, without guessing.
 *
 * PROVISIONAL - PENDING A SAMPLE OF THE CLIENT'S EXPORT. CDE confirmed on
 * 26 October 2026 that the DTR stays in the client's biometric system, and no
 * sample export has been received, so no production file layout is built in.
 * HR maps the columns of each file on the import screen: which holds the
 * employee's biometrics or employee number, the date (and in what format),
 * the time-in and the time-out. One row per employee per day is supported; a
 * raw punch log (one row per punch) is not, because pairing punches into
 * shifts needs rules CDE has not given.
 *
 * Nothing is written until HR has seen the preview and confirmed it, and even
 * then nothing already on file is overwritten unless HR chooses to replace it
 * and says why. Every row's outcome is kept with the import: what was
 * imported, what was already on file, what disagreed with the DTR on file, and
 * what could not be matched to an employee at this client.
 */
class DtrImportService
{
    public const IDENTIFIERS = [
        'biometric_number' => 'Biometrics number',
        'employee_number' => 'Employee number',
    ];

    /** Offered on the import screen; HR picks the one the file uses. */
    public const DATE_FORMATS = [
        'Y-m-d' => '2026-09-07',
        'Y/m/d' => '2026/09/07',
        'm/d/Y' => '09/07/2026 (month first)',
        'd/m/Y' => '07/09/2026 (day first)',
        'm-d-Y' => '09-07-2026 (month first)',
        'd-m-Y' => '07-09-2026 (day first)',
        'm/d/y' => '09/07/26 (month first)',
        'd/m/y' => '07/09/26 (day first)',
        'd-M-Y' => '07-Sep-2026',
        'M j, Y' => 'Sep 7, 2026',
    ];

    private const TIME_FORMATS = ['H:i', 'H:i:s', 'g:i A', 'g:iA', 'g:i:s A', 'g:i:sA'];

    public const ROW_LABELS = [
        'new' => 'Ready to import',
        'conflict' => 'Differs from the DTR on file',
        'unchanged' => 'Already on file',
        'blank_times' => 'No time-in or time-out',
        'duplicate_in_file' => 'Repeated in the file',
        'missing_identifier' => 'No employee identifier',
        'unmatched_employee' => 'No such employee',
        'ambiguous_identifier' => 'Matches more than one employee',
        'not_at_client' => 'Not placed at this client on that day',
        'name_mismatch' => 'Name does not match the employee',
        'missing_date' => 'No date',
        'invalid_date' => 'Date not in the chosen format',
        'invalid_time' => 'Time could not be read',
        'outside_period' => 'Outside the period of this file',
        'future_date' => 'Date is in the future',
        'outside_employment' => 'Outside the employment period',
        'conflicting_in_file' => 'Listed twice with different times',
    ];

    public const ERRORS = [
        'missing_identifier', 'unmatched_employee', 'ambiguous_identifier', 'not_at_client', 'name_mismatch',
        'missing_date', 'invalid_date', 'invalid_time', 'outside_period', 'future_date', 'outside_employment',
        'conflicting_in_file',
    ];

    /** Rows kept with an import's results, so one huge file cannot bloat it. */
    private const RESULT_ROW_LIMIT = 2000;

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly DocumentStorageService $storage,
        private readonly DtrFileReader $reader,
        private readonly AuditService $audit,
        private readonly ReferenceCodeService $codes,
    ) {
    }

    // ------------------------------------------------------------- uploading

    public function upload(UploadedFile $file, array $data, User $actor): AttendanceImport
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $contents = (string) file_get_contents($file->getRealPath());
        $hash = hash('sha256', $contents);

        $this->assertNotAlreadyImported($hash);

        $draft = AttendanceImport::where('file_hash', $hash)->where('status', 'draft')->first();

        if ($draft) {
            throw new RuntimeException(sprintf(
                'This file was already uploaded as %s and is waiting to be checked. Continue that import, or discard it first.',
                $draft->import_code
            ), 409);
        }

        // Opened before it is stored, so a file that cannot be read is never kept.
        $rows = $this->reader->read($contents, $extension, $this->maxRows());
        $stored = $this->storage->storeChecked($file, 'attendance-imports', (int) $data['client_company_id'], $extension);

        $import = DB::transaction(fn () => AttendanceImport::create([
            'import_code' => $this->codes->attendanceImport(),
            'client_company_id' => $data['client_company_id'],
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'source_note' => $data['source_note'] ?? null,
            'original_filename' => $stored['file_name'] ?: 'dtr.'.$extension,
            'file_disk' => (string) config('empower.uploads.disk'),
            'file_path' => $stored['file_path'],
            'file_hash' => $hash,
            'file_size_bytes' => (int) $stored['file_size_bytes'],
            'status' => 'draft',
            'total_rows' => max(0, count($rows) - 1),
            'uploaded_by' => $actor->id,
        ]));

        $this->audit->record('upload', 'attendance', AttendanceImport::class, $import->id, null, [
            'import_code' => $import->import_code,
            'file' => $import->original_filename,
            'period' => $import->period_from->toDateString().' to '.$import->period_to->toDateString(),
        ], clientCompanyId: $import->client_company_id);

        return $import;
    }

    /**
     * The file's columns and first rows, so HR can say which is which.
     */
    public function inspect(AttendanceImport $import): array
    {
        $rows = $this->rows($import);
        $mapping = $import->column_mapping ?? $this->suggest($rows);
        $hasHeader = (bool) ($mapping['has_header'] ?? true);
        $header = reset($rows);
        $width = max(array_map('count', array_slice($rows, 0, 50)));

        $columns = [];

        for ($i = 0; $i < $width; $i++) {
            $columns[] = [
                'index' => $i,
                'letter' => $this->letter($i),
                'header' => $hasHeader ? ($header[$i] ?? '') : '',
            ];
        }

        return [
            'columns' => $columns,
            'sample' => array_map(fn ($line, $cells) => ['line' => $line, 'cells' => $cells], array_keys(array_slice($rows, 0, 6, true)), array_slice($rows, 0, 6)),
            'mapping' => $mapping,
            'mapping_is_suggested' => $import->column_mapping === null,
        ];
    }

    /**
     * Saves the column choices and checks every row against them.
     */
    public function preview(AttendanceImport $import, array $mapping): array
    {
        if (! $import->isDraft()) {
            throw new RuntimeException('This import has already been '.$import->status.'.', 409);
        }

        $mapping = $this->normaliseMapping($mapping, $this->rows($import));
        $import->update(['column_mapping' => $mapping]);

        return $this->present($this->analyse($import, $mapping));
    }

    // ------------------------------------------------------------- importing

    public function confirm(AttendanceImport $import, string $conflictAction, ?string $reason, User $actor): AttendanceImport
    {
        $replace = $conflictAction === 'replace';

        if ($replace && blank($reason)) {
            throw new RuntimeException('Say why the DTR on file should be replaced by this file. The earlier times are kept.', 422);
        }

        return DB::transaction(function () use ($import, $replace, $reason, $actor) {
            $import = AttendanceImport::whereKey($import->id)->lockForUpdate()->firstOrFail();

            if (! $import->isDraft()) {
                throw new RuntimeException('This import has already been '.$import->status.'.', 409);
            }

            if ($import->column_mapping === null) {
                throw new RuntimeException('Choose the columns and check the preview before importing.', 422);
            }

            $this->assertNotAlreadyImported($import->file_hash, $import->id);

            // Checked again rather than trusted from the preview: the DTR on
            // file may have changed since.
            $analysis = $this->analyse($import, $import->column_mapping);
            $counts = ['imported' => 0, 'replaced' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0];
            $outcomes = [];

            if ($analysis['counts']['new'] === 0 && ! ($replace && $analysis['counts']['conflict'] > 0) && $analysis['covered'] === []) {
                throw new RuntimeException('Nothing in this file can be imported. Check the column choices and the rows marked as problems.', 422);
            }

            foreach ($analysis['rows'] as $row) {
                $date = $row['_date'];

                if ($row['status'] === 'new') {
                    $record = AttendanceRecord::create([
                        'employee_id' => $row['employee_id'],
                        'work_date' => $date->toDateString(),
                        'time_in' => $row['_in'],
                        'time_out' => $row['_out'],
                        'remarks' => $row['_remarks'],
                        'source' => 'import',
                        'attendance_import_id' => $import->id,
                        'source_row' => $row['line'],
                        'recorded_by' => $actor->id,
                    ]);
                    $this->attendance->recordRevision($row['employee_id'], $date, 'created', null, $record, $actor, null, $import->id);
                    $counts['imported']++;

                    continue;
                }

                if ($row['status'] === 'conflict' && $replace) {
                    $record = $row['_record'];
                    $before = clone $record;
                    $record->update([
                        'time_in' => $row['_in'],
                        'time_out' => $row['_out'],
                        'remarks' => $row['_remarks'] ?? $record->remarks,
                        'source' => 'import',
                        'attendance_import_id' => $import->id,
                        'source_row' => $row['line'],
                        'updated_by' => $actor->id,
                    ]);
                    $this->attendance->recordRevision($row['employee_id'], $date, 'updated', $before, $record, $actor, $reason, $import->id);
                    $counts['replaced']++;
                    $outcomes[] = ['status' => 'replaced'] + $row;

                    continue;
                }

                match (true) {
                    $row['status'] === 'unchanged' => $counts['unchanged']++,
                    in_array($row['status'], self::ERRORS, true) => $counts['errors']++,
                    default => $counts['skipped']++,
                };

                $outcomes[] = $row;
            }

            [$today, $from, $to] = [CarbonImmutable::today(), $import->period_from, $import->period_to];

            foreach ($analysis['covered'] as $employeeId) {
                [$first, $last] = $this->attendance->employmentWindow($analysis['employees'][$employeeId]);
                $start = $first && $from->lessThan($first) ? $first : CarbonImmutable::parse($from);
                $end = $last && $to->greaterThan($last) ? $last : CarbonImmutable::parse($to);
                $end = $end->greaterThan($today) ? $today : $end;

                if ($end->greaterThanOrEqualTo($start)) {
                    AttendanceCoveragePeriod::create([
                        'employee_id' => $employeeId,
                        'period_from' => $start->toDateString(),
                        'period_to' => $end->toDateString(),
                        'attendance_import_id' => $import->id,
                        'declared_by' => $actor->id,
                    ]);
                }
            }

            $import->update([
                'status' => 'completed',
                'imported_rows' => $counts['imported'],
                'replaced_rows' => $counts['replaced'],
                'unchanged_rows' => $counts['unchanged'],
                'skipped_rows' => $counts['skipped'],
                'error_rows' => $counts['errors'],
                'results' => [
                    'conflict_action' => $replace ? 'replace' : 'skip',
                    'replace_reason' => $replace ? $reason : null,
                    'rows' => array_map([$this, 'publicRow'], array_slice($outcomes, 0, self::RESULT_ROW_LIMIT)),
                    'rows_truncated' => count($outcomes) > self::RESULT_ROW_LIMIT,
                    'employees_without_rows' => $analysis['employees_without_rows'],
                    'covered_employees' => count($analysis['covered']),
                ],
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ]);

            $this->audit->record('import', 'attendance', AttendanceImport::class, $import->id, null, [
                'import_code' => $import->import_code,
                'imported' => $counts['imported'],
                'replaced' => $counts['replaced'],
                'unchanged' => $counts['unchanged'],
                'skipped' => $counts['skipped'],
                'errors' => $counts['errors'],
                'replace_reason' => $replace ? $reason : null,
            ], clientCompanyId: $import->client_company_id);

            return $import;
        });
    }

    /**
     * Abandons a draft. Nothing was written from it, so its file goes too.
     */
    public function discard(AttendanceImport $import, User $actor): AttendanceImport
    {
        if (! $import->isDraft()) {
            throw new RuntimeException('Only an import that has not been completed can be discarded.', 409);
        }

        $this->storage->delete($import->file_path);
        $import->update(['status' => 'discarded', 'discarded_at' => now(), 'file_path' => null]);

        $this->audit->record('discard', 'attendance', AttendanceImport::class, $import->id, null, [
            'import_code' => $import->import_code,
        ], clientCompanyId: $import->client_company_id, actor: $actor);

        return $import;
    }

    private function assertNotAlreadyImported(string $hash, ?int $exceptId = null): void
    {
        $previous = AttendanceImport::with('uploader')
            ->where('file_hash', $hash)
            ->where('status', 'completed')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();

        if ($previous) {
            throw new RuntimeException(sprintf(
                'This exact file was already imported as %s on %s by %s. Importing it again would only repeat it.',
                $previous->import_code,
                $previous->completed_at?->format('M j, Y'),
                $previous->uploader?->full_name ?? 'another user'
            ), 409);
        }
    }

    // -------------------------------------------------------------- analysis

    /**
     * Every row's outcome, worked out without writing anything.
     */
    private function analyse(AttendanceImport $import, array $mapping): array
    {
        $rows = $this->rows($import);
        $dataRows = $mapping['has_header'] ? array_slice($rows, 1, null, true) : $rows;
        $from = CarbonImmutable::parse($import->period_from)->startOfDay();
        $to = CarbonImmutable::parse($import->period_to)->startOfDay();
        $today = CarbonImmutable::today();
        $companyId = $import->client_company_id;
        $column = $mapping['identifier_type'];

        // Who could appear in this client's DTR: anybody placed there at some
        // point in the period. Biometrics numbers are the client's own, so
        // they are only matched among these.
        $candidates = Employee::query()
            ->with('applicant')
            ->whereIn('id', Deployment::query()
                ->where('client_company_id', $companyId)
                ->whereDate('deployment_date', '<=', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()))
                ->select('employee_id'))
            ->get()
            ->keyBy('id');

        $context = AttendanceContext::load($candidates->keys(), $from, $to);
        $byIdentifier = $candidates->groupBy(fn (Employee $e) => $this->normaliseIdentifier((string) $e->{$column}));
        $windows = $candidates->map(fn (Employee $e) => $this->attendance->employmentWindow($e));
        $comparison = $this->attendance->comparison();

        $results = [];

        foreach ($dataRows as $index => $cells) {
            $cell = fn (?int $i) => $i === null ? '' : trim((string) ($cells[$i] ?? ''));
            $identifier = $cell($mapping['identifier_column']);
            $rawDate = $cell($mapping['date_column']);
            $rawIn = $cell($mapping['time_in_column']);
            $rawOut = $cell($mapping['time_out_column']);
            $name = $cell($mapping['name_column'] ?? null);
            $remarks = $cell($mapping['remarks_column'] ?? null);

            if ($identifier === '' && $rawDate === '' && $rawIn === '' && $rawOut === '') {
                continue;
            }

            $row = [
                'line' => $index,
                'identifier' => $identifier,
                'name_in_file' => $name ?: null,
                'raw' => ['date' => $rawDate, 'time_in' => $rawIn, 'time_out' => $rawOut],
                'employee_id' => null,
                'employee_number' => null,
                'employee_name' => null,
                'work_date' => null,
                'time_in' => null,
                'time_out' => null,
                'time_out_next_day' => false,
                'on_file' => null,
                '_date' => null,
                '_in' => null,
                '_out' => null,
                '_remarks' => $remarks !== '' ? mb_substr($remarks, 0, 255) : null,
                '_record' => null,
            ];

            $results[] = $this->checkRow($row, $identifier, $rawDate, $rawIn, $rawOut, $name, $mapping, $byIdentifier,
                $windows, $context, $comparison, $companyId, $from, $to, $today);
        }

        $results = $this->markDuplicates($results);
        $results = $this->explainUnmatched($results, $column);

        $covered = collect($results)
            ->whereIn('status', ['new', 'unchanged', 'conflict', 'duplicate_in_file'])
            ->pluck('employee_id')->unique()->values()->all();

        $counts = array_fill_keys(array_keys(self::ROW_LABELS), 0);

        foreach ($results as $row) {
            $counts[$row['status']]++;
        }

        return [
            'rows' => $results,
            'counts' => $counts,
            'covered' => $covered,
            'employees' => $candidates->all(),
            'employees_without_rows' => $candidates->except($covered)
                ->filter(fn (Employee $e) => $this->placedDuring($context, $e->id, $companyId, $from, $to))
                ->map(fn (Employee $e) => ['employee_number' => $e->employee_number, 'full_name' => $e->full_name])
                ->sortBy('employee_number')->values()->all(),
        ];
    }

    private function checkRow(
        array $row, string $identifier, string $rawDate, string $rawIn, string $rawOut, string $name, array $mapping,
        Collection $byIdentifier, Collection $windows, AttendanceContext $context, $comparison, int $companyId,
        CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today,
    ): array {
        if ($identifier === '') {
            return $this->problem($row, 'missing_identifier');
        }

        $matches = $byIdentifier->get($this->normaliseIdentifier($identifier), collect());

        if ($matches->count() > 1) {
            return $this->problem($row, 'ambiguous_identifier');
        }

        /** @var Employee|null $employee */
        $employee = $matches->first();

        if (! $employee) {
            return $this->problem($row, 'unmatched_employee');
        }

        $row = array_merge($row, [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
        ]);

        if ($name !== '' && ! $this->nameMatches($employee, $name)) {
            return $this->problem($row, 'name_mismatch');
        }

        if ($rawDate === '') {
            return $this->problem($row, 'missing_date');
        }

        $date = $this->parseDate($rawDate, $mapping['date_format']);

        if (! $date) {
            return $this->problem($row, 'invalid_date');
        }

        $row['work_date'] = $date->toDateString();
        $row['_date'] = $date;

        if ($date->lessThan($from) || $date->greaterThan($to)) {
            return $this->problem($row, 'outside_period');
        }

        if ($date->greaterThan($today)) {
            return $this->problem($row, 'future_date');
        }

        [$first, $last] = $windows->get($employee->id);

        if (($first && $date->lessThan($first)) || ($last && $date->greaterThan($last))) {
            return $this->problem($row, 'outside_employment');
        }

        $placement = $context->placementsFor($employee->id)->on($date);

        if (($placement['client_company_id'] ?? null) !== $companyId) {
            return $this->problem($row, 'not_at_client');
        }

        $in = $this->parseTime($rawIn, $date, $mapping['date_format']);
        $out = $this->parseTime($rawOut, $date, $mapping['date_format']);

        if ($in === false || $out === false) {
            return $this->problem($row, 'invalid_time');
        }

        if ($in === null && $out === null) {
            return $this->problem($row, 'blank_times');
        }

        // Times written without a date are read the way a DTR sheet is: a
        // time-out not after the time-in is the next morning.
        $schedule = $comparison->scheduleFor($context->schedulesFor($employee->id), $date, $placement);
        [$timeIn, $timeOut] = $this->attendance->timestamps(
            $date,
            $in && ! $in['explicit'] ? $in['at']->format('H:i') : null,
            $out && ! $out['explicit'] ? $out['at']->format('H:i') : null,
            $schedule,
        );
        $timeIn = $in && $in['explicit'] ? $in['at'] : $timeIn;
        $timeOut = $out && $out['explicit'] ? $out['at'] : $timeOut;

        if ($timeIn && $timeOut && $timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut = $out['explicit'] ? $timeOut : $timeOut->addDay();
        }

        if ($timeIn && $timeOut && $timeOut->lessThanOrEqualTo($timeIn)) {
            return $this->problem($row, 'invalid_time');
        }

        $row = array_merge($row, [
            'time_in' => $timeIn?->format('H:i'),
            'time_out' => $timeOut?->format('H:i'),
            'time_out_next_day' => $timeOut !== null && $timeOut->toDateString() !== $date->toDateString(),
            '_in' => $timeIn,
            '_out' => $timeOut,
        ]);

        /** @var AttendanceRecord|null $record */
        $record = $context->recordsFor($employee->id)->get($date->toDateString());

        if (! $record) {
            return ['status' => 'new'] + $row;
        }

        $row['_record'] = $record;
        $row['on_file'] = [
            'time_in' => $record->time_in?->format('H:i'),
            'time_out' => $record->time_out?->format('H:i'),
            'source' => $record->source,
        ];

        return ['status' => $this->attendance->sameRecord($record, ['time_in' => $timeIn, 'time_out' => $timeOut]) ? 'unchanged' : 'conflict'] + $row;
    }

    /**
     * The same employee and day twice: once is kept if the times agree, and
     * neither is trusted if they do not.
     */
    private function markDuplicates(array $rows): array
    {
        $groups = [];

        foreach ($rows as $i => $row) {
            if (in_array($row['status'], ['new', 'unchanged', 'conflict'], true)) {
                $groups[$row['employee_id'].'|'.$row['work_date']][] = $i;
            }
        }

        foreach ($groups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $times = array_unique(array_map(fn ($i) => $rows[$i]['_in']?->format('c').'|'.$rows[$i]['_out']?->format('c'), $indexes));

            foreach ($indexes as $position => $i) {
                if (count($times) > 1) {
                    $rows[$i] = $this->problem($rows[$i], 'conflicting_in_file');
                } elseif ($position > 0) {
                    $rows[$i] = $this->problem($rows[$i], 'duplicate_in_file');
                }
            }
        }

        return $rows;
    }

    /**
     * Tells "no such employee" apart from "an employee, but not at this client".
     */
    private function explainUnmatched(array $rows, string $column): array
    {
        $unmatched = collect($rows)->where('status', 'unmatched_employee')->pluck('identifier')->unique()->values();

        if ($unmatched->isEmpty()) {
            return $rows;
        }

        $elsewhere = Employee::whereIn($column, $unmatched)->pluck($column)
            ->map(fn ($v) => $this->normaliseIdentifier((string) $v))->flip();

        foreach ($rows as $i => $row) {
            if ($row['status'] === 'unmatched_employee' && $elsewhere->has($this->normaliseIdentifier($row['identifier']))) {
                $rows[$i] = $this->problem($row, 'not_at_client');
            }
        }

        return $rows;
    }

    private function placedDuring(AttendanceContext $context, int $employeeId, int $companyId, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $timeline = $context->placementsFor($employeeId);

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            if (($timeline->on($date)['client_company_id'] ?? null) === $companyId) {
                return true;
            }
        }

        return false;
    }

    private function problem(array $row, string $status): array
    {
        return ['status' => $status] + $row;
    }

    // ---------------------------------------------------------------- parsing

    public function parseDate(string $value, string $format): ?CarbonImmutable
    {
        $candidates = [$value];

        // A spreadsheet date cell formatted with a time ("9/7/2026 0:00").
        if (! str_contains($format, ' ') && str_contains($value, ' ')) {
            $candidates[] = strtok($value, ' ');
        }

        foreach ($candidates as $candidate) {
            if ($parsed = $this->strict($format, $candidate)) {
                return CarbonImmutable::parse($parsed->format('Y-m-d'))->startOfDay();
            }
        }

        return null;
    }

    /**
     * A time on its own, or a date and time together.
     *
     * @return array{at: CarbonImmutable, explicit: bool}|false|null  null when
     *                                                                blank, false when unreadable
     */
    public function parseTime(string $value, CarbonImmutable $date, string $dateFormat): array|false|null
    {
        if ($value === '') {
            return null;
        }

        $value = str_replace(['A.M.', 'P.M.', 'NN'], ['AM', 'PM', 'PM'], strtoupper(preg_replace('/\s+/', ' ', $value)));
        $value = preg_replace('/\s*(AM|PM)$/', ' $1', $value);

        foreach (self::TIME_FORMATS as $format) {
            if ($parsed = $this->strict($format, $value) ?? $this->strict($format, str_replace(' ', '', $value))) {
                return ['at' => $date->setTime((int) $parsed->format('H'), (int) $parsed->format('i')), 'explicit' => false];
            }
        }

        foreach (self::TIME_FORMATS as $format) {
            if ($parsed = $this->strict($dateFormat.' '.$format, $value)) {
                $at = CarbonImmutable::parse($parsed->format('Y-m-d H:i'));

                // A punch on the work date, or the morning after it - anything
                // further away belongs to another day's row.
                if (in_array($at->toDateString(), [$date->toDateString(), $date->addDay()->toDateString()], true)) {
                    return ['at' => $at, 'explicit' => true];
                }

                return false;
            }
        }

        return false;
    }

    private function strict(string $format, string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $parsed;
    }

    private function normaliseIdentifier(string $value): string
    {
        $value = strtoupper(trim($value));

        // A spreadsheet drops leading zeros from a number: 0012 arrives as 12.
        return ctype_digit($value) ? (ltrim($value, '0') ?: '0') : $value;
    }

    /**
     * Whether the name in the file is plausibly this employee's: their surname
     * appears in it, ignoring case, spaces and punctuation.
     */
    private function nameMatches(Employee $employee, string $name): bool
    {
        $letters = fn (?string $s) => preg_replace('/[^a-z]/', '', strtolower(\Illuminate\Support\Str::ascii((string) $s)));
        $surname = $letters($employee->applicant?->last_name);

        return $surname === '' || str_contains($letters($name), $surname);
    }

    // ---------------------------------------------------------------- mapping

    private function normaliseMapping(array $mapping, array $rows): array
    {
        $width = max(array_map('count', array_slice($rows, 0, 50)));
        $columns = ['identifier_column', 'date_column', 'time_in_column', 'time_out_column', 'name_column', 'remarks_column'];

        foreach ($columns as $key) {
            $value = $mapping[$key] ?? null;

            if ($value !== null && $value !== '' && ((int) $value < 0 || (int) $value >= $width)) {
                throw new RuntimeException('One of the chosen columns is not in the file.', 422);
            }

            $mapping[$key] = $value === null || $value === '' ? null : (int) $value;
        }

        foreach (['identifier_column', 'date_column', 'time_in_column', 'time_out_column'] as $required) {
            if ($mapping[$required] === null) {
                throw new RuntimeException('Choose the columns for the employee, the date, the time-in and the time-out.', 422);
            }
        }

        $used = array_filter(array_intersect_key($mapping, array_flip($columns)), fn ($v) => $v !== null);

        if (count($used) !== count(array_unique($used))) {
            throw new RuntimeException('Each column can be used for only one thing.', 422);
        }

        return [
            'has_header' => (bool) ($mapping['has_header'] ?? true),
            'identifier_type' => $mapping['identifier_type'],
            'date_format' => $mapping['date_format'],
        ] + array_intersect_key($mapping, array_flip($columns));
    }

    /**
     * A first guess from the header row, for HR to confirm or change. Never
     * applied without being shown.
     */
    private function suggest(array $rows): array
    {
        $header = array_map(fn ($h) => strtolower((string) $h), reset($rows) ?: []);
        $find = function (array $patterns, array $exclude = []) use ($header) {
            foreach ($patterns as $pattern) {
                foreach ($header as $i => $label) {
                    if (! in_array($i, $exclude, true) && preg_match($pattern, $label)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $biometric = $find(['/bio/', '/ac.?no/', '/enrol/', '/badge/', '/user.?id/']);
        $employeeNumber = $find(['/emp.*(no|num|id|code)/']);
        $identifier = $biometric ?? $employeeNumber;
        $date = $find(['/date/', '/^day$/']);
        $in = $find(['/time.?in/', '/clock.?in/', '/check.?in/', '/\bin\b/']);
        $out = $find(['/time.?out/', '/clock.?out/', '/check.?out/', '/\bout\b/']);
        $taken = array_values(array_filter([$identifier, $date, $in, $out], fn ($v) => $v !== null));

        return [
            'has_header' => true,
            'identifier_type' => $biometric !== null || $employeeNumber === null ? 'biometric_number' : 'employee_number',
            'identifier_column' => $identifier,
            'date_column' => $date,
            'date_format' => $this->guessDateFormat($rows, $date),
            'time_in_column' => $in,
            'time_out_column' => $out,
            'name_column' => $find(['/name/'], $taken),
            'remarks_column' => $find(['/remark/', '/note/'], $taken),
        ];
    }

    private function guessDateFormat(array $rows, ?int $column): string
    {
        $samples = array_filter(array_map(fn ($r) => trim((string) ($r[$column] ?? '')), array_slice($rows, 1, 20)));

        if ($column === null || $samples === []) {
            return 'Y-m-d';
        }

        // The format most of the sample fits, so one stray row does not throw
        // the guess. Ties go to the earlier format in the list, which puts the
        // month first, as Excel does here by default; HR confirms either way.
        $best = 'Y-m-d';
        $bestCount = 0;

        foreach (array_keys(self::DATE_FORMATS) as $format) {
            $count = collect($samples)->filter(fn ($s) => $this->parseDate($s, $format) !== null)->count();

            if ($count > $bestCount) {
                [$best, $bestCount] = [$format, $count];
            }
        }

        return $best;
    }

    // ---------------------------------------------------------------- helpers

    private function rows(AttendanceImport $import): array
    {
        if (! $import->file_path) {
            throw new RuntimeException('The file for this import is no longer stored.', 410);
        }

        return $this->reader->read(
            $this->storage->contents($import->file_path),
            pathinfo($import->file_path, PATHINFO_EXTENSION),
            $this->maxRows()
        );
    }

    private function maxRows(): int
    {
        return (int) config('empower.attendance.import_max_rows', 10000);
    }

    private function letter(int $index): string
    {
        $letter = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + (($n - 1) % 26)).$letter;
        }

        return $letter;
    }

    /**
     * Preview: every problem, and a sample of what would be imported.
     */
    private function present(array $analysis): array
    {
        $rows = collect($analysis['rows']);
        $ready = $rows->where('status', 'new');
        $others = $rows->where('status', '!=', 'new');

        return [
            'counts' => $analysis['counts'],
            'ready' => $ready->count(),
            'errors' => $others->whereIn('status', self::ERRORS)->count(),
            'rows' => $others->take(self::RESULT_ROW_LIMIT)->concat($ready->take(200))
                ->sortBy('line')->map(fn ($r) => $this->publicRow($r))->values()->all(),
            'rows_truncated' => $others->count() > self::RESULT_ROW_LIMIT || $ready->count() > 200,
            'covered_employees' => count($analysis['covered']),
            'employees_without_rows' => $analysis['employees_without_rows'],
        ];
    }

    private function publicRow(array $row): array
    {
        $row['label'] = self::ROW_LABELS[$row['status']] ?? ($row['status'] === 'replaced' ? 'Replaced the DTR on file' : $row['status']);
        $row['is_error'] = in_array($row['status'], self::ERRORS, true);

        return array_filter($row, fn ($key) => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }
}
