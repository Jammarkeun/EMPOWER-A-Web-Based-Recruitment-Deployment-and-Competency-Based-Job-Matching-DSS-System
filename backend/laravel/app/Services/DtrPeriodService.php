<?php

namespace App\Services;

use App\Models\AttendanceDtrPeriod;
use App\Models\AttendanceDtrPeriodEvent;
use App\Models\AttendanceImport;
use App\Models\AttendanceReview;
use App\Models\ClientCompany;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Whether a client's DTR for a run of days has arrived in full.
 *
 * Receiving a file, importing it and knowing the submission is complete are
 * three different things. A client may send one cutoff in parts - a file per
 * department, or a late page - so an import succeeding says only that the
 * rows in it are on file. It is HR who confirms that nothing more is coming,
 * and only then does a scheduled day with nothing on file become "No DTR
 * record", a gap to verify. Until then it stays "Awaiting DTR". Neither is an
 * absence; that is only ever found through the attendance review.
 *
 *   received   - a file is in, none of it imported yet          (system)
 *   processing - imported, not yet checked for completeness     (system)
 *   incomplete - HR found something missing; a note says what   (HR)
 *   complete   - HR confirmed the DTR for the period is in full (HR)
 *
 * HR can reopen a decided period, with a reason. Every move is kept as an
 * event and in the audit trail.
 */
class DtrPeriodService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------- uploads

    /**
     * The period an uploaded file belongs to. Call inside the upload's
     * transaction.
     *
     * A file whose dates fit inside a period still open is filed under it -
     * the second half of a cutoff, or the other department's sheet. One whose
     * dates fit inside a period already confirmed complete is refused until
     * HR reopens it, so a confirmation is never silently overtaken. Anything
     * else starts a period of its own declared dates.
     */
    public function forUpload(int $companyId, CarbonImmutable $from, CarbonImmutable $to, User $actor): AttendanceDtrPeriod
    {
        // Serialises uploads and decisions for one client, so two people
        // cannot open the same period at once or confirm one mid-upload.
        ClientCompany::whereKey($companyId)->lockForUpdate()->first();

        $overlapping = AttendanceDtrPeriod::query()
            ->with('decider')
            ->where('client_company_id', $companyId)
            ->whereDate('period_from', '<=', $to->toDateString())
            ->whereDate('period_to', '>=', $from->toDateString())
            ->get();

        $confirmed = $overlapping->first(fn (AttendanceDtrPeriod $p) => $p->isComplete() && $p->contains($from, $to));

        if ($confirmed) {
            throw new RuntimeException(sprintf(
                'The DTR for %s was confirmed complete by %s on %s. If this file adds to it or corrects it, reopen that period first (DTR imports → DTR periods), saying why.',
                $this->range($confirmed),
                $confirmed->decider?->full_name ?? 'HR',
                $confirmed->status_at?->format('M j, Y') ?? 'an earlier date'
            ), 409);
        }

        $open = $overlapping
            ->reject(fn (AttendanceDtrPeriod $p) => $p->isComplete())
            ->filter(fn (AttendanceDtrPeriod $p) => $p->contains($from, $to))
            ->sortBy(fn (AttendanceDtrPeriod $p) => $p->period_from->diffInDays($p->period_to))
            ->first();

        return $open ?? AttendanceDtrPeriod::create([
            'client_company_id' => $companyId,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'status' => 'received',
            'created_by' => $actor->id,
        ]);
    }

    /**
     * A file under the period was imported: there is now something to check.
     * Call inside the import's transaction.
     */
    public function afterImport(AttendanceImport $import, User $actor): void
    {
        $period = AttendanceDtrPeriod::whereKey($import->attendance_dtr_period_id)->lockForUpdate()->first();

        if (! $period || $period->status === 'processing') {
            return;
        }

        $note = match ($period->status) {
            'received' => $import->import_code.' imported.',
            // Something was missing and more has come: worth another look.
            'incomplete' => $import->import_code.' imported after the period was marked incomplete. Check it again.',
            default => null,
        };

        if ($note !== null) {
            $this->move($period, 'processing', $note, null, $actor, system: true);
        }
    }

    /**
     * Refused when the file's period has been confirmed complete since it
     * was uploaded.
     */
    public function assertImportable(AttendanceImport $import): void
    {
        $period = $import->attendance_dtr_period_id ? AttendanceDtrPeriod::find($import->attendance_dtr_period_id) : null;

        if ($period?->isComplete()) {
            throw new RuntimeException(sprintf(
                'The DTR for %s has been confirmed complete since this file was uploaded. Reopen the period first if this file should still be imported.',
                $this->range($period)
            ), 409);
        }
    }

    // ----------------------------------------------------------- decisions

    /**
     * HR's call on the period: incomplete, complete, or reopen.
     */
    public function decide(AttendanceDtrPeriod $period, string $action, ?string $note, bool $acknowledgeGaps, User $actor): AttendanceDtrPeriod
    {
        $note = filled($note) ? trim($note) : null;

        return DB::transaction(function () use ($period, $action, $note, $acknowledgeGaps, $actor) {
            ClientCompany::whereKey($period->client_company_id)->lockForUpdate()->first();
            $period = AttendanceDtrPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();

            return match ($action) {
                'incomplete' => $this->markIncomplete($period, $note, $actor),
                'complete' => $this->confirmComplete($period, $note, $acknowledgeGaps, $actor),
                'reopen' => $this->reopen($period, $note, $actor),
                default => throw new RuntimeException('Unknown action.', 422),
            };
        });
    }

    private function markIncomplete(AttendanceDtrPeriod $period, ?string $note, User $actor): AttendanceDtrPeriod
    {
        if (! in_array($period->status, ['received', 'processing'], true)) {
            throw new RuntimeException('This period is '.$period->label().'. Reopen it first to change that.', 409);
        }

        if ($note === null) {
            throw new RuntimeException('Say what is missing from the submission - which employees, days or pages - so whoever follows it up knows what to ask the client for.', 422);
        }

        return $this->move($period, 'incomplete', $note, $this->reconciliation($period)['totals'], $actor);
    }

    private function confirmComplete(AttendanceDtrPeriod $period, ?string $note, bool $acknowledgeGaps, User $actor): AttendanceDtrPeriod
    {
        if (! in_array($period->status, ['processing', 'incomplete'], true)) {
            throw new RuntimeException($period->status === 'complete'
                ? 'This period is already confirmed complete.'
                : 'Nothing has been imported for this period yet. Import the client\'s file first.', 409);
        }

        $drafts = $period->imports()->where('status', 'draft')->pluck('import_code');

        if ($drafts->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'File %s for this period is still being checked. Import it or discard it before confirming the period.',
                $drafts->implode(', ')
            ), 409);
        }

        if (! $period->imports()->where('status', 'completed')->exists()) {
            throw new RuntimeException('Nothing has been imported for this period yet. Import the client\'s file first.', 409);
        }

        $reconciliation = $this->reconciliation($period);
        $gaps = $reconciliation['totals']['days_awaiting_dtr'];

        if ($gaps > 0 && ! $acknowledgeGaps) {
            throw new RuntimeException(sprintf(
                '%d scheduled day(s) in this period have nothing on file. Once you confirm, each becomes "No DTR record" for verification - not an absence. Tick the acknowledgement to go ahead.',
                $gaps
            ), 422);
        }

        return $this->move($period, 'complete', $note, $reconciliation['totals'] + [
            'employees_without_records' => collect($reconciliation['employees'])->where('records', 0)
                ->map(fn ($e) => $e['employee_number'].' '.$e['full_name'])->values()->all(),
        ], $actor);
    }

    private function reopen(AttendanceDtrPeriod $period, ?string $note, User $actor): AttendanceDtrPeriod
    {
        if (! in_array($period->status, AttendanceDtrPeriod::DECIDED, true)) {
            throw new RuntimeException('Only a period marked incomplete or complete can be reopened.', 409);
        }

        if ($note === null) {
            throw new RuntimeException('Say why the period is being reopened.', 422);
        }

        if ($period->isComplete()) {
            $this->assertNoGapUnderReview($period);
        }

        $next = $period->imports()->where('status', 'completed')->exists() ? 'processing' : 'received';

        return $this->move($period, $next, $note, null, $actor);
    }

    /**
     * Reopening turns "No DTR record" days back into "Awaiting DTR". A gap HR
     * has already started verifying would be left with a review of a day that
     * no longer says what was reviewed, so it is finished first.
     */
    private function assertNoGapUnderReview(AttendanceDtrPeriod $period): void
    {
        $open = AttendanceReview::query()
            ->with('employee')
            ->where('client_company_id', $period->client_company_id)
            ->whereDate('work_date', '>=', $period->period_from->toDateString())
            ->whereDate('work_date', '<=', $period->period_to->toDateString())
            ->where('status', '!=', 'closed')
            ->get()
            ->filter(fn (AttendanceReview $r) => in_array('no_record', (array) $r->discrepancy_types, true));

        if ($open->isNotEmpty()) {
            $first = $open->first();

            throw new RuntimeException(sprintf(
                '%d "No DTR record" day(s) in this period are being reviewed (the first: %s on %s). Close those reviews before reopening the period.',
                $open->count(),
                $first->employee?->full_name ?? 'an employee',
                $first->work_date->format('M j, Y')
            ), 409);
        }
    }

    private function move(AttendanceDtrPeriod $period, string $to, ?string $note, ?array $details, User $actor, bool $system = false): AttendanceDtrPeriod
    {
        $from = $period->status;

        $period->update($system ? ['status' => $to] : [
            'status' => $to,
            'status_note' => $note,
            'status_by' => $actor->id,
            'status_at' => now(),
        ]);

        AttendanceDtrPeriodEvent::create([
            'attendance_dtr_period_id' => $period->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'details' => $details,
            'actor_id' => $actor->id,
        ]);

        $this->audit->record($system ? 'update' : ($to === 'complete' ? 'confirm' : 'update'), 'attendance', AttendanceDtrPeriod::class, $period->id,
            ['status' => $from],
            ['status' => $to, 'period' => $this->range($period), 'note' => $note] + ($details ? ['details' => $details] : []),
            clientCompanyId: $period->client_company_id,
            actor: $actor,
        );

        return $period;
    }

    // ------------------------------------------------------ reconciliation

    /**
     * Who was placed at the client during the period, and how much of each
     * one's DTR is on file - what HR looks at before calling it complete.
     *
     * Read from the same comparison every attendance screen uses, and counted
     * only on the days each employee was placed at this client.
     */
    public function reconciliation(AttendanceDtrPeriod $period): array
    {
        $from = CarbonImmutable::parse($period->period_from)->startOfDay();
        $to = CarbonImmutable::parse($period->period_to)->startOfDay();
        $companyId = $period->client_company_id;

        $employees = Employee::query()
            ->with('applicant')
            ->whereIn('id', Deployment::query()
                ->where('client_company_id', $companyId)
                ->whereDate('deployment_date', '<=', $to->toDateString())
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()))
                ->select('employee_id'))
            ->orderBy('employee_number')
            ->get();

        $context = AttendanceContext::load($employees->pluck('id'), $from, $to);
        $rows = [];

        foreach ($employees as $employee) {
            $days = collect($this->attendance->compare($employee, $from, $to, $context)['days'])
                ->filter(fn ($d) => ($d['client_company_id'] ?? null) === $companyId && $d['status'] !== 'outside_employment');

            if ($days->isEmpty()) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'days_at_client' => $days->count(),
                'scheduled_days' => $days->where('is_scheduled', true)->count(),
                'records' => $days->where('has_record', true)->count(),
                'days_awaiting_dtr' => $days->where('status', 'awaiting_dtr')->count(),
                'days_no_record' => $days->filter(fn ($d) => in_array('no_record', $d['discrepancies'], true))->count(),
                'has_schedule' => $context->schedulesFor($employee->id)->isNotEmpty(),
            ];
        }

        $rows = collect($rows);

        return [
            'employees' => $rows->values()->all(),
            'totals' => [
                'employees_placed' => $rows->count(),
                'employees_with_records' => $rows->where('records', '>', 0)->count(),
                'records' => $rows->sum('records'),
                'scheduled_days' => $rows->sum('scheduled_days'),
                'days_awaiting_dtr' => $rows->sum('days_awaiting_dtr'),
                'days_no_record' => $rows->sum('days_no_record'),
            ],
        ];
    }

    // ----------------------------------------------------------- presenting

    public function present(AttendanceDtrPeriod $period, bool $detailed = false): array
    {
        $imports = $period->relationLoaded('imports') ? $period->imports : $period->imports()->get();

        return [
            'id' => $period->id,
            'client_company_id' => $period->client_company_id,
            'company' => $period->company?->company_name,
            'period_from' => $period->period_from->toDateString(),
            'period_to' => $period->period_to->toDateString(),
            'status' => $period->status,
            'status_label' => $period->label(),
            'status_note' => $period->status_note,
            'status_by' => $period->decider?->full_name,
            'status_at' => $period->status_at?->toIso8601String(),
            'files' => [
                'imported' => $imports->where('status', 'completed')->count(),
                'drafts' => $imports->where('status', 'draft')->count(),
                'discarded' => $imports->where('status', 'discarded')->count(),
            ],
            'created_at' => $period->created_at?->toIso8601String(),
        ] + ($detailed ? [
            'imports' => $imports->sortByDesc('id')->map(fn (AttendanceImport $i) => [
                'id' => $i->id,
                'import_code' => $i->import_code,
                'status' => $i->status,
                'original_filename' => $i->original_filename,
                'period_from' => $i->period_from->toDateString(),
                'period_to' => $i->period_to->toDateString(),
                'imported_rows' => $i->imported_rows,
                'error_rows' => $i->error_rows,
                'uploaded_by' => $i->uploader?->full_name,
                'uploaded_at' => $i->created_at?->toIso8601String(),
            ])->values()->all(),
            'events' => $period->events()->with('actor')->get()->map(fn (AttendanceDtrPeriodEvent $e) => [
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'to_label' => AttendanceDtrPeriod::LABELS[$e->to_status] ?? $e->to_status,
                'note' => $e->note,
                'details' => $e->details,
                'actor' => $e->actor?->full_name,
                'at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'reconciliation' => $this->reconciliation($period),
        ] : []);
    }

    private function range(AttendanceDtrPeriod $period): string
    {
        return sprintf('%s from %s to %s',
            $period->company?->company_name ?? 'this client',
            $period->period_from->format('M j, Y'),
            $period->period_to->format('M j, Y'));
    }
}
