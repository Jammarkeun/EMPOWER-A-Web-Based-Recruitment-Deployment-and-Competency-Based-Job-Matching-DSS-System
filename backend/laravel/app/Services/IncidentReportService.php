<?php

namespace App\Services;

use App\Models\ClientDepartment;
use App\Models\CoordinatorAssignment;
use App\Models\IncidentReport;
use App\Models\IncidentReportNote;
use App\Models\User;
use App\Notifications\IncidentReportSubmitted;
use App\Notifications\IncidentReportUpdated;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A coordinator's incident report, from draft to "case resolved".
 *
 *   draft ──submit──▶ submitted ──▶ under_review ("case pending") ──▶ resolved
 *                         └──────────────────────────────────────────▶ resolved
 *                                       ▲                                 │
 *                                       └──────────── reopened ───────────┘
 *
 * The coordinator writes and submits. Management answers with notes, and only
 * management moves the status. The coordinator may add follow-up notes to the
 * thread once it is sent. Nothing here involves an employee's record: an
 * incident is never a violation.
 */
class IncidentReportService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentStorageService $storage,
        private readonly ReferenceCodeService $codes,
    ) {
    }

    // ------------------------------------------------------------ coordinator

    public function create(CoordinatorAssignment $assignment, User $coordinator, array $data, ?UploadedFile $evidence): IncidentReport
    {
        $this->assertDepartmentInScope($assignment, $data['client_department_id'] ?? null);

        $report = DB::transaction(fn () => IncidentReport::create([
            'report_code' => $this->codes->incidentReport(),
            'client_company_id' => $assignment->client_company_id,
            'client_department_id' => $data['client_department_id'] ?? null,
            'reported_by' => $coordinator->id,
            'title' => $data['title'],
            'incident_date' => $data['incident_date'],
            'incident_time' => $data['incident_time'] ?? null,
            'location' => $data['location'] ?? null,
            'details' => $data['details'],
            'status' => 'draft',
        ]));

        $this->audit->record('create', 'incident_reports', IncidentReport::class, $report->id, null, [
            'report_code' => $report->report_code,
            'title' => $report->title,
        ], clientCompanyId: $report->client_company_id);

        if ($evidence) {
            $this->attachEvidence($report, $evidence);
        }

        return $report->fresh();
    }

    public function update(IncidentReport $report, CoordinatorAssignment $assignment, User $coordinator, array $data, ?UploadedFile $evidence): IncidentReport
    {
        $this->assertOwnEditable($report, $coordinator);

        if (array_key_exists('client_department_id', $data)) {
            $this->assertDepartmentInScope($assignment, $data['client_department_id']);
        }

        $before = $report->only(array_keys($data));
        $report->update($data);

        $this->audit->recordUpdate('incident_reports', IncidentReport::class, $report->id, $before, $data);

        if ($evidence) {
            $this->attachEvidence($report, $evidence);
        }

        return $report->fresh();
    }

    public function submit(IncidentReport $report, User $coordinator): IncidentReport
    {
        $this->assertOwnEditable($report, $coordinator);

        $report->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->audit->record('submit', 'incident_reports', IncidentReport::class, $report->id,
            ['status' => 'draft'], ['status' => 'submitted'], clientCompanyId: $report->client_company_id);

        $report->load(['company', 'reporter']);

        $this->reviewers()->each->notify(new IncidentReportSubmitted($report));

        return $report;
    }

    /** A draft that was never sent can be discarded. Anything sent stays. */
    public function discard(IncidentReport $report, User $coordinator): void
    {
        if ($report->status !== 'draft' || (int) $report->reported_by !== (int) $coordinator->id) {
            throw new RuntimeException('Only your own unsent draft can be discarded.', 403);
        }

        $this->audit->record('delete', 'incident_reports', IncidentReport::class, $report->id,
            ['report_code' => $report->report_code, 'status' => 'draft'], null,
            clientCompanyId: $report->client_company_id);

        if ($report->evidence_path) {
            $this->storage->delete($report->evidence_path);
        }

        $report->delete();
    }

    // ------------------------------------------------------------ the thread

    /**
     * Adds a note, and - for management only - moves the status.
     *
     * A note is needed for anything but acknowledging a new report: closing a
     * case or reopening one has to say why, so the coordinator and later
     * readers know.
     */
    public function addNote(IncidentReport $report, User $author, ?string $note, ?string $status, bool $asManagement): IncidentReport
    {
        $note = filled($note) ? trim($note) : null;

        if ($report->status === 'draft') {
            throw new RuntimeException('This report has not been sent yet.', 409);
        }

        if ($status !== null && ! $asManagement) {
            throw new RuntimeException('Only the office can mark a report pending or resolved.', 403);
        }

        $from = $report->status;
        $to = $status !== null && $status !== $from ? $status : null;

        if ($to !== null && ! in_array($to, $this->allowedFrom($from), true)) {
            throw new RuntimeException(sprintf(
                'A report that is %s cannot be marked %s.',
                strtolower(IncidentReport::LABELS[$from] ?? $from),
                strtolower(IncidentReport::LABELS[$to] ?? $to)
            ), 409);
        }

        $acknowledging = $from === 'submitted' && $to === 'under_review';

        if ($note === null && ! $acknowledging) {
            throw ValidationException::withMessages([
                'note' => [$to === null ? 'Write the note.' : 'Say why, so the coordinator knows.'],
            ]);
        }

        DB::transaction(function () use ($report, $author, $note, $from, $to) {
            IncidentReportNote::create([
                'incident_report_id' => $report->id,
                'author_id' => $author->id,
                'author_role' => $author->user_type,
                'note' => $note ?? IncidentReport::LABELS[$to],
                'status_from' => $to ? $from : null,
                'status_to' => $to,
            ]);

            if ($to !== null) {
                $report->update([
                    'status' => $to,
                    'resolved_at' => $to === 'resolved' ? now() : null,
                    'resolved_by' => $to === 'resolved' ? $author->id : null,
                ]);
            }
        });

        $this->audit->record($to ? 'review' : 'note', 'incident_reports', IncidentReport::class, $report->id,
            $to ? ['status' => $from] : null,
            array_filter(['status' => $to, 'note' => $note], fn ($v) => $v !== null),
            clientCompanyId: $report->client_company_id);

        $report->refresh()->load(['company', 'reporter']);

        if ($asManagement) {
            if ($report->reporter?->is_active && (int) $report->reporter->id !== (int) $author->id) {
                $report->reporter->notify(new IncidentReportUpdated($report, $author, $note, forOffice: false));
            }
        } else {
            $this->reviewers()->each->notify(new IncidentReportUpdated($report, $author, $note, forOffice: true));
        }

        return $report;
    }

    /** @return list<string> */
    private function allowedFrom(string $from): array
    {
        return match ($from) {
            'submitted' => ['under_review', 'resolved'],
            'under_review' => ['resolved'],
            // Reopening a closed case.
            'resolved' => ['under_review'],
            default => [],
        };
    }

    // --------------------------------------------------------------- helpers

    private function reviewers()
    {
        return User::permission('incident_reports.review')->where('is_active', true)->get();
    }

    private function assertDepartmentInScope(CoordinatorAssignment $assignment, mixed $departmentId): void
    {
        if (blank($departmentId)) {
            return;
        }

        $inScope = ClientDepartment::query()
            ->whereKey((int) $departmentId)
            ->where('client_company_id', $assignment->client_company_id)
            ->exists();

        if (! $inScope) {
            throw new RuntimeException('That department is not under your assigned client company.', 403);
        }
    }

    private function assertOwnEditable(IncidentReport $report, User $coordinator): void
    {
        if ((int) $report->reported_by !== (int) $coordinator->id) {
            throw new RuntimeException('Only the coordinator who wrote this report can change it.', 403);
        }

        if (! $report->isEditable()) {
            throw new RuntimeException('This report has been sent and can no longer be changed. Add a note instead.', 409);
        }
    }

    private function attachEvidence(IncidentReport $report, UploadedFile $file): void
    {
        $previous = $report->evidence_path;
        $stored = $this->storage->store($file, 'incident-reports', $report->client_company_id);

        $report->update([
            'evidence_path' => $stored['file_path'],
            'evidence_name' => $stored['file_name'],
        ]);

        // Still a draft, so nothing else has seen the file it replaces.
        if ($previous) {
            $this->storage->delete($previous);
        }

        $this->audit->record('upload', 'incident_reports', IncidentReport::class, $report->id, null, [
            'evidence' => $stored['file_name'],
        ], clientCompanyId: $report->client_company_id);
    }
}
