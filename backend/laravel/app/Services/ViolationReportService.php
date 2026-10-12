<?php

namespace App\Services;

use App\Models\CoordinatorAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Models\ViolationReport;
use App\Notifications\ViolationReportReviewed;
use App\Notifications\ViolationReportSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A coordinator's incident report, from draft to HR's decision.
 *
 *   draft ──submit──▶ submitted ──▶ under_review ──▶ validated  (official violation created)
 *     ▲                   │               │       └─▶ dismissed  (nothing recorded)
 *     └──── returned ◀────┴───────────────┘
 *
 * The coordinator writes and submits; HR reviews; only validation creates an
 * official violation, through the same service HR uses when recording one
 * directly. A coordinator can never record, finalise, or terminate anything.
 *
 * The client and department on a report are stamped from the coordinator's
 * assignment and the employee's placement. Nothing a coordinator sends can
 * place a report under another client.
 */
class ViolationReportService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentStorageService $storage,
        private readonly ReferenceCodeService $codes,
        private readonly ViolationService $violations,
    ) {
    }

    // ------------------------------------------------------------ coordinator

    public function create(CoordinatorAssignment $assignment, User $coordinator, array $data, ?UploadedFile $evidence): ViolationReport
    {
        $employee = $this->employeeInScope($assignment, (int) $data['employee_id']);

        $report = DB::transaction(function () use ($assignment, $coordinator, $data, $employee) {
            return ViolationReport::create([
                'report_code' => $this->codes->violationReport(),
                'employee_id' => $employee->id,
                'client_company_id' => $assignment->client_company_id,
                'client_department_id' => $employee->current_department_id,
                'reported_by' => $coordinator->id,
                'violation_type' => $data['violation_type'],
                'incident_date' => $data['incident_date'],
                'details' => $data['details'],
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
            ]);
        });

        $this->audit->record('create', 'violation_reports', ViolationReport::class, $report->id, null, [
            'report_code' => $report->report_code,
            'employee_number' => $employee->employee_number,
            'violation_type' => $report->violation_type,
        ], clientCompanyId: $report->client_company_id);

        if ($evidence) {
            $this->attachEvidence($report, $evidence);
        }

        return $report;
    }

    public function update(ViolationReport $report, User $coordinator, array $data, ?UploadedFile $evidence): ViolationReport
    {
        $this->assertOwnEditable($report, $coordinator);

        $before = $report->only(array_keys($data));
        $report->update($data);

        $this->audit->recordUpdate('violation_reports', ViolationReport::class, $report->id, $before, $data);

        if ($evidence) {
            $this->attachEvidence($report, $evidence);
        }

        return $report->fresh();
    }

    public function submit(ViolationReport $report, User $coordinator): ViolationReport
    {
        $this->assertOwnEditable($report, $coordinator);

        if (! $report->employee?->isActive()) {
            throw new RuntimeException('This employee is no longer active, so the report cannot be submitted.', 422);
        }

        $from = $report->status;
        $report->update(['status' => 'submitted', 'submitted_at' => now()]);

        $this->audit->record('submit', 'violation_reports', ViolationReport::class, $report->id,
            ['status' => $from], ['status' => 'submitted'], clientCompanyId: $report->client_company_id);

        $report->load(['employee.applicant', 'company', 'reporter']);

        User::permission('violation_reports.review')
            ->where('is_active', true)
            ->get()
            ->each->notify(new ViolationReportSubmitted($report));

        return $report;
    }

    /**
     * A draft that was never sent can be discarded. Anything HR has seen stays.
     */
    public function discard(ViolationReport $report, User $coordinator): void
    {
        if ($report->status !== 'draft' || (int) $report->reported_by !== (int) $coordinator->id) {
            throw new RuntimeException('Only your own unsent draft can be discarded.', 403);
        }

        $this->audit->record('delete', 'violation_reports', ViolationReport::class, $report->id,
            ['report_code' => $report->report_code, 'status' => 'draft'], null,
            clientCompanyId: $report->client_company_id);

        $report->delete();
    }

    // -------------------------------------------------------------------- HR

    /**
     * HR's decision on a submitted report.
     *
     * @param  string  $action  start_review | return | validate | dismiss
     */
    public function review(ViolationReport $report, User $reviewer, string $action, array $data = []): ViolationReport
    {
        if (! $report->isAwaitingReview()) {
            throw new RuntimeException(sprintf(
                'This report is %s and is not waiting for review.',
                strtolower(ViolationReport::LABELS[$report->status] ?? $report->status)
            ), 409);
        }

        $to = match ($action) {
            'start_review' => 'under_review',
            'return' => 'returned',
            'validate' => 'validated',
            'dismiss' => 'dismissed',
        };

        if ($action === 'start_review' && $report->status !== 'submitted') {
            throw new RuntimeException('This report is already under review.', 409);
        }

        $from = $report->status;

        DB::transaction(function () use ($report, $reviewer, $action, $data, $to) {
            if ($action === 'validate') {
                $report->loadMissing('employee');

                $this->violations->record($report->employee, [
                    'violation_date' => $report->incident_date->toDateString(),
                    // HR may correct the classification when validating.
                    'violation_type' => $data['violation_type'] ?? $report->violation_type,
                    'description' => $report->details,
                    'penalty' => $data['penalty'] ?? null,
                    'remarks' => $data['note'] ?? $report->notes,
                ], $reviewer, $report);
            }

            $report->update([
                'status' => $to,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
            ]);
        });

        $this->audit->record('review', 'violation_reports', ViolationReport::class, $report->id,
            ['status' => $from], ['status' => $to, 'note' => $data['note'] ?? null],
            clientCompanyId: $report->client_company_id);

        $report->refresh()->load(['employee.applicant', 'reporter']);

        if ($report->reporter?->is_active) {
            $report->reporter->notify(new ViolationReportReviewed($report));
        }

        return $report;
    }

    // --------------------------------------------------------------- helpers

    /**
     * The employee, if and only if they are an active worker at the
     * coordinator's client. The id comes from the request; the check does not.
     */
    public function employeeInScope(CoordinatorAssignment $assignment, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('current_client_company_id', $assignment->client_company_id)
            ->first();

        if (! $employee) {
            throw new RuntimeException('That employee is not deployed with your assigned client company.', 403);
        }

        if (! $employee->isActive()) {
            throw new RuntimeException('Reports can only be made about an active employee.', 422);
        }

        return $employee;
    }

    private function assertOwnEditable(ViolationReport $report, User $coordinator): void
    {
        if ((int) $report->reported_by !== (int) $coordinator->id) {
            throw new RuntimeException('Only the coordinator who wrote this report can change it.', 403);
        }

        if (! $report->isEditable()) {
            throw new RuntimeException('This report has been sent to HR and can no longer be changed.', 409);
        }
    }

    private function attachEvidence(ViolationReport $report, UploadedFile $file): void
    {
        $stored = $this->storage->store($file, 'violation-reports', $report->employee_id);

        $report->update([
            'evidence_path' => $stored['file_path'],
            'evidence_name' => $stored['file_name'],
        ]);

        $this->audit->record('upload', 'violation_reports', ViolationReport::class, $report->id, null, [
            'evidence' => $stored['file_name'],
        ], clientCompanyId: $report->client_company_id);
    }
}
