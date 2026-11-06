<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\EmployeeViolation;
use App\Models\IncidentReport;
use App\Models\Termination;
use App\Models\TerminationClearanceItem;
use App\Models\User;
use App\Models\ViolationReport;

/**
 * One termination case as the review and clearance screen needs it.
 *
 * Everything is read from the records the request is linked to - its own
 * employee and placement - never from an id the screen sends. The three kinds
 * of record the clearance looks at are kept apart, because they mean
 * different things:
 *
 * - obligations: EMPOWER keeps no loan or cash-advance records, and says so,
 *   rather than showing an empty list that would read as "none owed";
 * - violations: the official record HR validated, and, separately, the
 *   coordinators' reports that were never validated and so are not findings;
 * - incidents: site reports, which have no employee. Those from the
 *   employee's own placements and dates are shown as context only, never as
 *   the employee's, and nothing about them blocks or justifies anything.
 *
 * Which of these a person sees follows the permissions they already hold.
 */
class TerminationCasePresenter
{
    public function __construct(
        private readonly SeparationPresenter $separations,
    ) {
    }

    public function present(Termination $termination, User $user): array
    {
        $termination->loadMissing([
            'employee.applicant',
            'employee.currentCompany',
            'employee.currentDepartment',
            'deployment.company',
            'deployment.department',
            'creator',
            'approver',
            'reviewer',
            'finalizer',
            'withdrawer',
            'clearanceItems.verifier',
            'events.actor',
        ]);

        $employee = $termination->employee;
        $outstanding = $termination->outstandingClearance();

        return [
            'id' => $termination->id,
            'reference' => $termination->reference,
            'status' => $termination->status,
            'status_label' => Termination::LABELS[$termination->status] ?? $termination->status,
            'stage' => $termination->stage(),
            'stage_label' => $termination->stageLabel(),
            'is_open' => $termination->isOpen(),
            'is_editable' => $termination->isEditable(),
            'is_legacy' => $termination->isLegacy(),

            'reason' => $termination->reason,
            'termination_date' => $termination->termination_date?->toDateString(),
            'remarks' => $termination->remarks,
            'has_documents' => filled($termination->documents_path),
            'employee_message' => $termination->employee_message,

            'filed_by' => $termination->creator?->full_name,
            'filed_at' => $termination->created_at?->toIso8601String(),
            'submitted_at' => $termination->submitted_at?->toIso8601String(),
            'review' => [
                'decided_by' => $termination->reviewer?->full_name,
                'decided_at' => $termination->reviewed_at?->toIso8601String(),
                'note' => $termination->review_note,
                'approved_by' => $termination->approved_at ? $termination->approver?->full_name : null,
                'approved_at' => $termination->approved_at?->toIso8601String(),
            ],
            'finalized_by' => $termination->finalizer?->full_name,
            'finalized_at' => $termination->finalized_at?->toIso8601String(),
            'withdrawn_by' => $termination->withdrawer?->full_name,
            'withdrawn_at' => $termination->withdrawn_at?->toIso8601String(),
            'withdrawal_reason' => $termination->withdrawal_reason,

            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'employment_status' => $employee->employment_status,
                'hire_date' => $employee->hire_date?->toDateString(),
                'position' => $employee->current_position_title,
                'company' => $employee->currentCompany?->company_name,
                'department' => $employee->currentDepartment?->department_name,
                'supervisor' => $employee->current_supervisor_name,
            ],
            'assignment' => $this->assignment($termination->deployment ?? $employee->activeDeployment()?->load('company', 'department')),

            'clearance' => [
                'items' => $termination->clearanceItems->map(fn (TerminationClearanceItem $item) => [
                    'id' => $item->id,
                    'key' => $item->item_key,
                    'label' => $item->label,
                    'kind' => $item->kind,
                    'description' => $item->description,
                    'is_mandatory' => $item->is_mandatory,
                    'requires_remarks' => $item->requires_remarks,
                    'status' => $item->status,
                    'status_label' => $item->statusLabel(),
                    'is_resolved' => $item->isResolved(),
                    'allowed_statuses' => $item->allowedStatuses(),
                    'remarks' => $item->remarks,
                    'verified_by' => $item->verifier?->full_name,
                    'verified_at' => $item->verified_at?->toIso8601String(),
                ])->values()->all(),
                'outstanding' => $outstanding->pluck('label')->all(),
                'complete' => $termination->status === 'approved' ? $outstanding->isEmpty() : null,
                'status' => $termination->clearance_status,
            ],

            'obligations' => [
                // No table in EMPOWER holds loans, salary or cash advances, or
                // any other amount owed. Said plainly, so an empty section is
                // never read as "nothing owed".
                'records_available' => false,
                'message' => 'EMPOWER does not keep loan, salary-advance or cash-advance records. '
                    .'Whether anything is owed must be confirmed with the office that keeps them, '
                    .'and recorded on the "Outstanding obligations verified" item of the clearance.',
            ],

            'violations' => $user->can('violations.view') ? $this->violations($termination) : null,
            'incidents' => $user->can('incident_reports.view') ? $this->incidents($termination) : null,

            'notice' => $this->separations->staffNoticeStatus($termination),
            'history' => $this->separations->staffHistory($termination->events),
            'can' => $this->abilities($termination, $user, $outstanding->isEmpty()),
        ];
    }

    private function assignment(?Deployment $deployment): ?array
    {
        if (! $deployment) {
            return null;
        }

        return [
            'deployment_code' => $deployment->deployment_code,
            'company' => $deployment->company?->company_name,
            'department' => $deployment->department?->department_name,
            'position' => $deployment->position_title,
            'deployment_date' => $deployment->deployment_date?->toDateString(),
            'end_date' => $deployment->end_date?->toDateString(),
            'status' => $deployment->deployment_status,
        ];
    }

    /**
     * The disciplinary record, unchanged, beside the reports that never
     * became part of it.
     */
    private function violations(Termination $termination): array
    {
        $employeeId = $termination->employee_id;

        $official = EmployeeViolation::query()
            ->where('employee_id', $employeeId)
            ->with(['company', 'department', 'issuer', 'reporter', 'report'])
            ->orderByDesc('violation_date')
            ->orderByDesc('id')
            ->get();

        // Reports a coordinator sent that HR has not validated - waiting,
        // returned, or dismissed. Drafts are still the coordinator's own.
        // A validated report is the official record above, not a second entry.
        $reports = ViolationReport::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['submitted', 'under_review', 'returned', 'dismissed'])
            ->with('company')
            ->orderByDesc('incident_date')
            ->orderByDesc('id')
            ->get();

        return [
            'official' => $official->map(fn (EmployeeViolation $v) => [
                'id' => $v->id,
                'date' => $v->violation_date?->toDateString(),
                'type' => $v->violation_type,
                'description' => $v->description,
                'company' => $v->company?->company_name,
                'department' => $v->department?->department_name,
                'status' => $v->status,
                'penalty' => $v->penalty,
                'remarks' => $v->remarks,
                'resolution_date' => $v->resolution_date?->toDateString(),
                'recorded_by' => $v->issuer?->full_name,
                'recorded_at' => $v->created_at?->toIso8601String(),
                // The coordinator's report it was validated from, if any.
                'reported_by' => $v->reporter?->full_name,
                'from_report' => $v->report?->report_code,
                'report_id' => $v->violation_report_id,
                // Whether a file exists; it is opened through the existing
                // short-lived evidence link, never by its path.
                'has_evidence' => filled($v->evidence_path),
                'counts_toward_threshold' => $v->isActive(),
                'expires_on' => $v->expiresOn()?->toDateString(),
            ])->values()->all(),
            'unvalidated_reports' => $reports->map(fn (ViolationReport $r) => [
                'id' => $r->id,
                'report_code' => $r->report_code,
                'date' => $r->incident_date?->toDateString(),
                'type' => $r->violation_type,
                'company' => $r->company?->company_name,
                'status' => $r->status,
                'status_label' => ViolationReport::LABELS[$r->status] ?? $r->status,
            ])->values()->all(),
            'active_count' => $official->filter->isActive()->count(),
            'threshold' => (int) config('empower.violations.termination_threshold', 4),
        ];
    }

    /**
     * Site incident reports from the employee's own placements, while they
     * were there. Context, never attribution: an incident report names no
     * employee, so none of these is this person's.
     */
    private function incidents(Termination $termination): array
    {
        $placements = $termination->employee->deployments()->get();

        $reports = $placements->isEmpty() ? collect() : IncidentReport::query()
            ->where('status', '!=', 'draft')
            ->where(function ($query) use ($placements) {
                foreach ($placements as $placement) {
                    $query->orWhere(function ($q) use ($placement) {
                        $q->where('client_company_id', $placement->client_company_id)
                            ->whereDate('incident_date', '>=', $placement->deployment_date)
                            ->when($placement->end_date, fn ($q) => $q->whereDate('incident_date', '<=', $placement->end_date))
                            // The same department, or a report for the whole site.
                            ->when($placement->client_department_id, fn ($q) => $q->where(fn ($d) => $d
                                ->where('client_department_id', $placement->client_department_id)
                                ->orWhereNull('client_department_id')));
                    });
                }
            })
            ->with(['company', 'department'])
            ->orderByDesc('incident_date')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return [
            'note' => 'Incident reports describe events at a client site and name no employee. These are the reports '
                .'from this employee\'s placements during their assignment, shown for context only. None is a '
                .'violation, none is attributed to this employee, and none affects the clearance.',
            'context' => $reports->map(fn (IncidentReport $r) => [
                'id' => $r->id,
                'report_code' => $r->report_code,
                'title' => $r->title,
                'date' => $r->incident_date?->toDateString(),
                'company' => $r->company?->company_name,
                'department' => $r->department?->department_name,
                'status' => $r->status,
                'status_label' => IncidentReport::LABELS[$r->status] ?? $r->status,
            ])->values()->all(),
        ];
    }

    /**
     * What this person may do with the case now. The same rules are enforced
     * again by the policy and the service; these only decide what is offered.
     */
    private function abilities(Termination $termination, User $user, bool $clearanceComplete): array
    {
        $filedByUser = (int) $termination->created_by === (int) $user->id;
        $mayReview = $user->can('separation.review_termination');
        $mayFinalise = $user->can('separation.finalize_termination');

        return [
            'edit' => $user->can('separation.create') && $termination->isEditable(),
            'submit' => $user->can('separation.create') && $termination->isEditable(),
            'review' => $mayReview && $termination->status === 'for_review' && ! $filedByUser,
            'review_blocked' => $mayReview && $termination->status === 'for_review' && $filedByUser
                ? 'You filed this request, so another administrator must review it.'
                : null,
            'update_clearance' => $mayFinalise && $termination->status === 'approved',
            'finalise' => $mayFinalise && $termination->status === 'approved' && $clearanceComplete,
            'withdraw' => ($user->can('separation.create') || $mayReview) && $termination->isOpen(),
            'update_legacy_clearance' => $user->can('separation.create') && $termination->isLegacy(),
        ];
    }
}
