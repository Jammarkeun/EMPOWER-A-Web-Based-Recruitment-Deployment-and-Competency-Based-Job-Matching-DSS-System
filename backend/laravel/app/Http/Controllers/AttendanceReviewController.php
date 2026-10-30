<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AttendanceReview;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\Employee;
use App\Services\Attendance\AttendanceComparison;
use App\Services\Attendance\AttendanceContext;
use App\Services\Attendance\AttendancePresenter;
use App\Services\AttendanceReviewService;
use App\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The queue of flagged days, and HR's decisions on them.
 *
 * Every day the comparison flags appears here as "For review" until somebody
 * acts on it, together with any day already under review - including one a
 * correction has since cleared, which still needs closing. Filters cover the
 * period, the employee, the client and department they were placed at on the
 * day, the kind of discrepancy, and the review status.
 */
class AttendanceReviewController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceReviewService $reviews,
        private readonly AttendancePresenter $present,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAnyAttendance', Employee::class);

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'search' => ['nullable', 'string', 'max:120'],
            'discrepancy' => ['nullable', Rule::in(array_keys(AttendanceComparison::DISCREPANCY_LABELS))],
            'status' => ['nullable', Rule::in([...array_keys(AttendanceReview::LABELS), 'open'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        [$from, $to] = $this->attendance->period($filters['from'] ?? null, $filters['to'] ?? null);

        $employees = AttendanceController::employeesInPeriod($from, $to, $filters)
            ->with('applicant')
            ->orderBy('employee_number')
            ->get();

        $context = AttendanceContext::load($employees->pluck('id'), $from, $to);
        $reviews = AttendanceReview::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (AttendanceReview $r) => $r->employee_id.'|'.$r->work_date->toDateString());

        $rows = collect();
        $insufficient = ['no_dtr' => 0, 'no_schedule' => 0, 'awaiting_dtr_days' => 0];

        foreach ($employees as $employee) {
            $comparison = $this->attendance->compare($employee, $from, $to, $context);
            $summary = $comparison['summary'];

            if (in_array($summary['data_status'], ['no_dtr', 'no_schedule'], true)) {
                $insufficient[$summary['data_status']]++;
            }

            $insufficient['awaiting_dtr_days'] += $summary['awaiting_dtr_days'];

            foreach ($comparison['days'] as $day) {
                $review = $reviews->get($employee->id.'|'.$day['date']);
                $status = AttendanceReview::statusFor($day, $review);

                if ($status !== null) {
                    $rows->push(['employee' => $employee, 'day' => $day, 'review' => $review, 'status' => $status]);
                }
            }
        }

        // Narrowed by where the worker was placed on the day itself.
        $rows = $rows
            ->when($filters['client_company_id'] ?? null, fn ($c, $v) => $c->filter(
                fn ($r) => ($r['review']?->client_company_id ?? $r['day']['client_company_id']) === (int) $v
            ))
            ->when($filters['client_department_id'] ?? null, fn ($c, $v) => $c->filter(
                fn ($r) => ($r['review']?->client_department_id ?? $r['day']['client_department_id']) === (int) $v
            ))
            ->when($filters['discrepancy'] ?? null, fn ($c, $v) => $c->filter(
                fn ($r) => in_array($v, $r['day']['discrepancies'], true) || in_array($v, $r['review']?->discrepancy_types ?? [], true)
            ));

        $counts = array_fill_keys(array_keys(AttendanceReview::LABELS), 0);

        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }

        $status = $filters['status'] ?? 'open';
        $rows = $rows
            ->filter(fn ($r) => $status === 'open' ? $r['status'] !== 'closed' : $r['status'] === $status)
            ->sortBy([fn ($a, $b) => strcmp($b['day']['date'], $a['day']['date']), fn ($a, $b) => strcmp($a['employee']->employee_number, $b['employee']->employee_number)])
            ->values();

        $perPage = $filters['per_page'] ?? 25;
        $page = max(1, (int) $request->query('page', 1));
        $pageRows = $rows->forPage($page, $perPage)->values();

        $companies = ClientCompany::whereIn('id', $pageRows->map(fn ($r) => $r['review']?->client_company_id ?? $r['day']['client_company_id'])->filter()->unique())
            ->pluck('company_name', 'id');
        $departments = ClientDepartment::whereIn('id', $pageRows->map(fn ($r) => $r['review']?->client_department_id ?? $r['day']['client_department_id'])->filter()->unique())
            ->pluck('department_name', 'id');

        return ApiResponse::success(
            $pageRows->map(function ($r) use ($companies, $departments) {
                $companyId = $r['review']?->client_company_id ?? $r['day']['client_company_id'];
                $departmentId = $r['review']?->client_department_id ?? $r['day']['client_department_id'];

                return [
                    'employee' => [
                        'id' => $r['employee']->id,
                        'employee_number' => $r['employee']->employee_number,
                        'full_name' => $r['employee']->full_name,
                        'employment_status' => $r['employee']->employment_status,
                    ],
                    'company' => $companies->get($companyId),
                    'department' => $departments->get($departmentId),
                ] + $this->present->day($r['day'], $r['review']);
            }),
            'Attendance discrepancies',
            200,
            [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rows->count(),
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'status_counts' => $counts,
                'insufficient_data' => $insufficient,
                'employees_compared' => $employees->count(),
                'late_grace_minutes' => $this->attendance->policy()['late_grace_minutes'],
            ]
        );
    }

    /**
     * Moves one day's review: under verification, verified, corrected, closed.
     */
    public function store(Request $request, Employee $employee, string $date): JsonResponse
    {
        $this->authorize('reviewAttendance', Employee::class);
        $this->authorize('viewAttendance', $employee);

        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(AttendanceReview::LABELS))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $this->reviews->transition(
            $employee,
            CarbonImmutable::parse($date)->startOfDay(),
            $data['status'],
            $data['note'] ?? null,
            $request->user()
        );

        return ApiResponse::success($this->present->review($review), 'Review saved: '.$review->label());
    }
}
