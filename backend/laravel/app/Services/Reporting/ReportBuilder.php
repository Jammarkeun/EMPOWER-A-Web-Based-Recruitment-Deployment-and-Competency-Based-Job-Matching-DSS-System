<?php

namespace App\Services\Reporting;

use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\Deployment;
use App\Models\Employee;
use App\Models\EmployeeViolation;
use App\Models\JobRequest;
use App\Models\Resignation;
use App\Models\Termination;
use App\Models\Training;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Builds the datasets behind every report in the system.
 *
 * Each report returns the same envelope — title, subtitle, columns, rows, and a
 * summary — so a single PDF template and a single Excel exporter can render any
 * of them. Adding a report means adding one method here, not a new template and
 * a new export class each time.
 *
 * Rows are flat arrays of already-formatted strings. Formatting belongs here
 * rather than in the template because the same figures have to come out
 * identically in PDF, in Excel, and on screen.
 */
class ReportBuilder
{
    public const TYPES = [
        'applicants' => 'Applicant Report',
        'employees' => 'Employee Masterlist',
        'deployments' => 'Deployment Report',
        'violations' => 'Disciplinary Report',
        'clients' => 'Client Company Report',
        'job_requests' => 'Manpower Request Report',
        'training' => 'Training Report',
        'resignations' => 'Resignation Report',
        'terminations' => 'Termination Report',
        'annual_summary' => 'Annual Summary',
    ];

    public function build(string $type, array $filters = []): array
    {
        if (! array_key_exists($type, self::TYPES)) {
            throw new InvalidArgumentException("Unknown report type: {$type}");
        }

        $method = 'build'.str_replace(' ', '', ucwords(str_replace('_', ' ', $type)));

        $report = $this->{$method}($filters);

        return array_merge([
            'type' => $type,
            'generated_at' => now()->format('j F Y, g:i a'),
            'period' => $this->describePeriod($filters),
            'filters' => $filters,
        ], $report);
    }

    // ------------------------------------------------------------- applicants

    private function buildApplicants(array $filters): array
    {
        $rows = Applicant::query()
            ->with(['educations', 'experiences'])
            ->when($filters['current_status'] ?? null, fn ($q, $v) => $q->where('current_status', $v))
            ->when($filters['folder_category'] ?? null, fn ($q, $v) => $q->where('folder_category', $v))
            ->when($filters['source_channel'] ?? null, fn ($q, $v) => $q->where('source_channel', $v))
            ->when($filters['position_title'] ?? null, fn ($q, $v) => $q->where('preferred_position', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('application_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('application_date', '<=', $v))
            ->orderByDesc('application_date')
            ->get()
            ->map(fn (Applicant $a) => [
                $a->applicant_code,
                $a->full_name,
                $a->sex ? ucfirst($a->sex) : '—',
                $a->age ?? '—',
                $a->contact_number ?: '—',
                $a->preferred_position ?: '—',
                $this->humanise($a->current_status),
                str_replace('folder_', 'Folder ', (string) $a->folder_category),
                $a->application_date?->format('d M Y') ?? '—',
            ]);

        return [
            'title' => 'Applicant Report',
            'subtitle' => 'Applicants on record with their current stage and document status',
            'columns' => ['Code', 'Name', 'Sex', 'Age', 'Contact', 'Position Sought', 'Status', 'Folder', 'Applied'],
            'widths' => ['10%', '20%', '6%', '5%', '12%', '15%', '14%', '9%', '9%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total applicants' => $rows->count(),
                'Deployment ready' => $rows->filter(fn ($r) => $r[7] === 'Folder 1')->count(),
                'Primary complete' => $rows->filter(fn ($r) => $r[7] === 'Folder 2')->count(),
                'Resume only' => $rows->filter(fn ($r) => $r[7] === 'Folder 3')->count(),
            ]),
        ];
    }

    // -------------------------------------------------------------- employees

    private function buildEmployees(array $filters): array
    {
        $rows = Employee::query()
            ->with(['applicant', 'currentCompany', 'currentDepartment'])
            ->withCount('violations')
            ->when($filters['employment_status'] ?? null, fn ($q, $v) => $q->where('employment_status', $v))
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('current_client_company_id', $v))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('current_department_id', $v))
            ->when($filters['position_title'] ?? null, fn ($q, $v) => $q->where('current_position_title', $v))
            // Hire date, which is the only date an employee record carries.
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('hire_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('hire_date', '<=', $v))
            ->orderBy('employee_number')
            ->get()
            ->map(fn (Employee $e) => [
                $e->employee_number,
                $e->full_name ?? '—',
                $e->currentCompany?->company_name ?? '—',
                $e->currentDepartment?->department_name ?? '—',
                $e->current_position_title ?: '—',
                $e->current_supervisor_name ?: '—',
                $e->hire_date?->format('d M Y') ?? '—',
                ucfirst((string) $e->employment_status),
                (string) $e->violations_count,
            ]);

        return [
            'title' => 'Employee Masterlist',
            'subtitle' => 'Deployed workers and their current assignments',
            'columns' => ['Emp. No.', 'Name', 'Client', 'Department', 'Position', 'Supervisor', 'Hired', 'Status', 'Violations'],
            'widths' => ['10%', '18%', '15%', '12%', '13%', '12%', '8%', '7%', '5%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total employees' => $rows->count(),
                'Active' => $rows->filter(fn ($r) => $r[7] === 'Active')->count(),
                'Resigned' => $rows->filter(fn ($r) => $r[7] === 'Resigned')->count(),
                'Terminated' => $rows->filter(fn ($r) => $r[7] === 'Terminated')->count(),
            ]),
        ];
    }

    // ------------------------------------------------------------ deployments

    private function buildDeployments(array $filters): array
    {
        $rows = Deployment::query()
            ->with(['employee.applicant', 'company', 'department'])
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('client_department_id', $v))
            ->when($filters['position_title'] ?? null, fn ($q, $v) => $q->where('position_title', $v))
            ->when($filters['deployment_status'] ?? null, fn ($q, $v) => $q->where('deployment_status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('deployment_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('deployment_date', '<=', $v))
            ->orderByDesc('deployment_date')
            ->get()
            ->map(fn (Deployment $d) => [
                $d->deployment_code,
                $d->employee?->employee_number ?? '—',
                $d->employee?->full_name ?? '—',
                $d->company?->company_name ?? '—',
                $d->department?->department_name ?? '—',
                $d->position_title,
                $d->deployment_date?->format('d M Y') ?? '—',
                $d->end_date?->format('d M Y') ?? 'Ongoing',
                ucfirst((string) $d->deployment_status),
            ]);

        return [
            'title' => 'Deployment Report',
            'subtitle' => 'Placements recorded against client companies',
            'columns' => ['Code', 'Emp. No.', 'Employee', 'Client', 'Department', 'Position', 'Deployed', 'Ended', 'Status'],
            'widths' => ['11%', '9%', '16%', '15%', '12%', '13%', '8%', '8%', '8%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total deployments' => $rows->count(),
                'Currently active' => $rows->filter(fn ($r) => $r[8] === 'Active')->count(),
                'Ended' => $rows->filter(fn ($r) => in_array($r[8], ['Ended', 'Completed'], true))->count(),
            ]),
        ];
    }

    // ------------------------------------------------------------- violations

    private function buildViolations(array $filters): array
    {
        $rows = EmployeeViolation::query()
            ->with(['employee.applicant', 'employee.currentCompany', 'issuer'])
            ->when($filters['violation_type'] ?? null, fn ($q, $v) => $q->where('violation_type', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('violation_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('violation_date', '<=', $v))
            ->orderByDesc('violation_date')
            ->get()
            ->map(fn (EmployeeViolation $v) => [
                $v->violation_date?->format('d M Y') ?? '—',
                $v->employee?->employee_number ?? '—',
                $v->employee?->full_name ?? '—',
                $v->employee?->currentCompany?->company_name ?? '—',
                $this->humanise($v->violation_type),
                mb_strimwidth((string) $v->description, 0, 70, '…'),
                $v->penalty ?: '—',
                $this->humanise($v->status),
            ]);

        return [
            'title' => 'Disciplinary Report',
            'subtitle' => 'Violations recorded against deployed employees',
            'columns' => ['Date', 'Emp. No.', 'Employee', 'Client', 'Type', 'Description', 'Penalty', 'Status'],
            'widths' => ['8%', '9%', '15%', '14%', '11%', '23%', '11%', '9%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total violations' => $rows->count(),
                'Unresolved' => $rows->filter(fn ($r) => in_array($r[7], ['Open', 'Under Review', 'Escalated'], true))->count(),
                'Resolved' => $rows->filter(fn ($r) => $r[7] === 'Resolved')->count(),
            ]),
        ];
    }

    // ---------------------------------------------------------------- clients

    private function buildClients(array $filters): array
    {
        $rows = ClientCompany::query()
            ->withCount(['departments', 'jobRequests', 'employees'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('company_name')
            ->get()
            ->map(fn (ClientCompany $c) => [
                $c->company_code,
                $c->company_name,
                $c->business_type,
                $c->contact_person ?: '—',
                $c->contact_number ?: '—',
                (string) $c->departments_count,
                (string) $c->job_requests_count,
                (string) $c->employees_count,
                ucfirst((string) $c->status),
            ]);

        return [
            'title' => 'Client Company Report',
            'subtitle' => 'Client accounts with their placement volume',
            'columns' => ['Code', 'Company', 'Business Type', 'Contact', 'Number', 'Depts', 'Requests', 'Deployed', 'Status'],
            'widths' => ['9%', '21%', '15%', '14%', '12%', '6%', '8%', '8%', '7%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Client companies' => $rows->count(),
                'Active accounts' => $rows->filter(fn ($r) => $r[8] === 'Active')->count(),
                'Workers deployed' => $rows->sum(fn ($r) => (int) $r[7]),
            ]),
        ];
    }

    // ----------------------------------------------------------- job requests

    private function buildJobRequests(array $filters): array
    {
        $rows = JobRequest::query()
            ->with(['company', 'department'])
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('client_department_id', $v))
            ->when($filters['position_title'] ?? null, fn ($q, $v) => $q->where('position_title', $v))
            ->when($filters['request_status'] ?? null, fn ($q, $v) => $q->where('request_status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('date_requested', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('date_requested', '<=', $v))
            ->orderByDesc('date_requested')
            ->get()
            ->map(fn (JobRequest $r) => [
                $r->request_code,
                $r->company?->company_name ?? '—',
                $r->department?->department_name ?? '—',
                $r->position_title,
                (string) $r->workers_needed,
                (string) $r->workers_fulfilled,
                (string) $r->remaining_headcount,
                $r->date_requested?->format('d M Y') ?? '—',
                $r->deployment_deadline?->format('d M Y') ?? '—',
                $this->humanise($r->request_status),
            ]);

        return [
            'title' => 'Manpower Request Report',
            'subtitle' => 'Staffing requests received from client companies',
            'columns' => ['Code', 'Client', 'Department', 'Position', 'Needed', 'Filled', 'Left', 'Requested', 'Deadline', 'Status'],
            'widths' => ['10%', '15%', '11%', '14%', '6%', '6%', '5%', '9%', '9%', '15%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total requests' => $rows->count(),
                'Positions requested' => $rows->sum(fn ($r) => (int) $r[4]),
                'Positions filled' => $rows->sum(fn ($r) => (int) $r[5]),
                'Still to fill' => $rows->sum(fn ($r) => (int) $r[6]),
            ]),
        ];
    }

    // --------------------------------------------------------------- training

    private function buildTraining(array $filters): array
    {
        $rows = Training::query()
            ->withCount([
                'enrollments',
                'enrollments as attended_count' => fn ($q) => $q->where('attendance_status', 'present'),
                'enrollments as completed_count' => fn ($q) => $q->where('completion_status', 'completed'),
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('training_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('training_date', '<=', $v))
            ->orderByDesc('training_date')
            ->get()
            ->map(fn (Training $t) => [
                $t->training_code,
                $t->training_title,
                $t->training_date?->format('d M Y') ?? '—',
                $t->location,
                $t->trainer_name,
                (string) $t->enrollments_count,
                (string) $t->attended_count,
                (string) $t->completed_count,
                ucfirst((string) $t->status),
            ]);

        return [
            'title' => 'Training Report',
            'subtitle' => 'Sessions delivered with attendance and completion',
            'columns' => ['Code', 'Title', 'Date', 'Location', 'Trainer', 'Enrolled', 'Attended', 'Completed', 'Status'],
            'widths' => ['10%', '20%', '9%', '15%', '14%', '8%', '8%', '9%', '7%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Sessions' => $rows->count(),
                'Total enrolled' => $rows->sum(fn ($r) => (int) $r[5]),
                'Total completed' => $rows->sum(fn ($r) => (int) $r[7]),
            ]),
        ];
    }

    // ------------------------------------------------------------ separations

    private function buildResignations(array $filters): array
    {
        $rows = Resignation::query()
            ->with(['employee.applicant', 'employee.currentCompany'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('filing_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('filing_date', '<=', $v))
            ->orderByDesc('filing_date')
            ->get()
            ->map(fn (Resignation $r) => [
                $r->employee?->employee_number ?? '—',
                $r->employee?->full_name ?? '—',
                $r->employee?->currentCompany?->company_name ?? '—',
                mb_strimwidth((string) $r->reason, 0, 45, '…'),
                $r->filing_date?->format('d M Y') ?? '—',
                $r->rendering_days !== null ? $r->rendering_days.' days' : '—',
                $r->exit_date?->format('d M Y') ?? '—',
                $this->humanise($r->clearance_status),
                $this->humanise($r->status),
            ]);

        return [
            'title' => 'Resignation Report',
            'subtitle' => 'Voluntary separations and their clearance status',
            'columns' => ['Emp. No.', 'Employee', 'Client', 'Reason', 'Filed', 'Notice', 'Exit Date', 'Clearance', 'Status'],
            'widths' => ['9%', '16%', '14%', '18%', '9%', '8%', '9%', '9%', '8%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total resignations' => $rows->count(),
                'Completed' => $rows->filter(fn ($r) => $r[8] === 'Completed')->count(),
                'Awaiting clearance' => $rows->filter(fn ($r) => $r[7] !== 'Cleared')->count(),
            ]),
        ];
    }

    private function buildTerminations(array $filters): array
    {
        $rows = Termination::query()
            ->with(['employee.applicant', 'employee.currentCompany', 'approver'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('termination_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('termination_date', '<=', $v))
            ->orderByDesc('termination_date')
            ->get()
            ->map(fn (Termination $t) => [
                $t->employee?->employee_number ?? '—',
                $t->employee?->full_name ?? '—',
                $t->employee?->currentCompany?->company_name ?? '—',
                mb_strimwidth((string) $t->reason, 0, 55, '…'),
                $t->termination_date?->format('d M Y') ?? '—',
                $t->approver?->full_name ?? '—',
                $this->humanise($t->status),
            ]);

        return [
            'title' => 'Termination Report',
            'subtitle' => 'Involuntary separations with approving authority',
            'columns' => ['Emp. No.', 'Employee', 'Client', 'Reason', 'Date', 'Approved By', 'Status'],
            'widths' => ['10%', '18%', '16%', '24%', '10%', '14%', '8%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Total terminations' => $rows->count(),
                'Finalised' => $rows->filter(fn ($r) => $r[6] === 'Finalized')->count(),
                'Awaiting review' => $rows->filter(fn ($r) => $r[6] === 'For Review')->count(),
            ]),
        ];
    }

    // --------------------------------------------------------- annual summary

    /**
     * A year of activity in one page, month by month.
     *
     * Hiring and attrition sit side by side deliberately: a deployment count read
     * alone looks like growth when it may only be replacing leavers.
     */
    private function buildAnnualSummary(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);

        $rows = collect(range(1, 12))->map(function (int $month) use ($year) {
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $applications = Applicant::whereBetween('application_date', [$start, $end])->count();
            $deployments = Deployment::whereBetween('deployment_date', [$start, $end])->count();
            $resignations = Resignation::where('status', 'completed')
                ->whereBetween('exit_date', [$start, $end])->count();
            $terminations = Termination::where('status', 'finalized')
                ->whereBetween('termination_date', [$start, $end])->count();
            $violations = EmployeeViolation::whereBetween('violation_date', [$start, $end])->count();

            return [
                $start->format('F'),
                (string) $applications,
                (string) $deployments,
                (string) $resignations,
                (string) $terminations,
                (string) $violations,
                // Net movement is the figure that actually answers "did the
                // deployed workforce grow this month?".
                sprintf('%+d', $deployments - $resignations - $terminations),
            ];
        });

        return [
            'title' => 'Annual Summary — '.$year,
            'subtitle' => 'Recruitment and attrition by month',
            'columns' => ['Month', 'Applications', 'Deployments', 'Resignations', 'Terminations', 'Violations', 'Net Change'],
            'widths' => ['16%', '14%', '14%', '14%', '14%', '14%', '14%'],
            'rows' => $rows,
            'summary' => $this->summarise([
                'Applications received' => $rows->sum(fn ($r) => (int) $r[1]),
                'Workers deployed' => $rows->sum(fn ($r) => (int) $r[2]),
                'Separations' => $rows->sum(fn ($r) => (int) $r[3] + (int) $r[4]),
                'Net workforce change' => sprintf('%+d', $rows->sum(fn ($r) => (int) $r[6])),
            ]),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function summarise(array $figures): array
    {
        return collect($figures)->map(fn ($value, $label) => [
            'label' => $label,
            'value' => is_int($value) ? number_format($value) : (string) $value,
        ])->values()->all();
    }

    private function humanise(?string $value): string
    {
        return $value ? ucwords(str_replace('_', ' ', $value)) : '—';
    }

    private function describePeriod(array $filters): string
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;

        if ($from && $to) {
            return Carbon::parse($from)->format('j M Y').' to '.Carbon::parse($to)->format('j M Y');
        }

        if ($from) {
            return 'From '.Carbon::parse($from)->format('j M Y');
        }

        if ($to) {
            return 'Up to '.Carbon::parse($to)->format('j M Y');
        }

        if (isset($filters['year'])) {
            return 'Calendar year '.$filters['year'];
        }

        return 'All records';
    }
}
