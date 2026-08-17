<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Applicant;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\JobRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One search box across every record type.
 *
 * This answers the problem the agency actually described: "HR manually searches
 * applicants using folders and Ctrl + F in Excel." A search that only works
 * within the screen you happen to be on does not replace that — someone at the
 * counter with a name and a phone number needs to find that person without
 * first deciding whether they are an applicant, an employee, or neither.
 *
 * Results are permission-filtered per type, so a user only ever sees what their
 * role already allows them to open.
 */
class SearchController extends Controller
{
    private const PER_TYPE_LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $term = trim($data['q']);
        $user = $request->user();

        $groups = [];

        if ($user->can('applicants.view')) {
            $groups[] = $this->applicants($term);
        }

        if ($user->can('employees.view')) {
            $groups[] = $this->employees($term);
        }

        if ($user->can('job_requests.view')) {
            $groups[] = $this->jobRequests($term);
        }

        if ($user->can('clients.view')) {
            $groups[] = $this->clients($term);
        }

        $groups = array_values(array_filter($groups, fn ($group) => $group['results'] !== []));

        return ApiResponse::success([
            'query' => $term,
            'groups' => $groups,
            'total' => array_sum(array_map(fn ($g) => count($g['results']), $groups)),
        ]);
    }

    private function applicants(string $term): array
    {
        $results = Applicant::query()
            ->search($term)
            ->orderByDesc('application_date')
            ->limit(self::PER_TYPE_LIMIT)
            ->get()
            ->map(fn (Applicant $a) => [
                'id' => $a->id,
                'title' => $a->full_name,
                // The subtitle carries what someone at the counter would use to
                // confirm they have the right person.
                'subtitle' => implode(' · ', array_filter([
                    $a->applicant_code,
                    $a->contact_number,
                    ucwords(str_replace('_', ' ', $a->current_status)),
                ])),
                'badge' => str_replace('folder_', 'Folder ', (string) $a->folder_category),
                'url' => "/applicants/{$a->id}",
            ]);

        return ['type' => 'applicants', 'label' => 'Applicants', 'results' => $results->all()];
    }

    private function employees(string $term): array
    {
        $operator = $this->likeOperator();

        $results = Employee::query()
            ->with(['applicant', 'currentCompany'])
            ->where(fn ($q) => $q
                ->where('employee_number', $operator, "%{$term}%")
                ->orWhereHas('applicant', fn ($a) => $a->search($term)))
            ->limit(self::PER_TYPE_LIMIT)
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'title' => $e->full_name ?? $e->employee_number,
                'subtitle' => implode(' · ', array_filter([
                    $e->employee_number,
                    $e->currentCompany?->company_name,
                    $e->current_position_title,
                ])),
                'badge' => ucfirst((string) $e->employment_status),
                'url' => "/employees/{$e->id}",
            ]);

        return ['type' => 'employees', 'label' => 'Employees', 'results' => $results->all()];
    }

    private function jobRequests(string $term): array
    {
        $operator = $this->likeOperator();

        $results = JobRequest::query()
            ->with(['company', 'department'])
            ->where(fn ($q) => $q
                ->where('request_code', $operator, "%{$term}%")
                ->orWhere('position_title', $operator, "%{$term}%"))
            ->orderByDesc('date_requested')
            ->limit(self::PER_TYPE_LIMIT)
            ->get()
            ->map(fn (JobRequest $r) => [
                'id' => $r->id,
                'title' => $r->position_title,
                'subtitle' => implode(' · ', array_filter([
                    $r->request_code,
                    $r->company?->company_name,
                    "{$r->workers_fulfilled}/{$r->workers_needed} filled",
                ])),
                'badge' => ucwords(str_replace('_', ' ', (string) $r->request_status)),
                'url' => "/job-requests/{$r->id}",
            ]);

        return ['type' => 'job_requests', 'label' => 'Manpower requests', 'results' => $results->all()];
    }

    private function clients(string $term): array
    {
        $operator = $this->likeOperator();

        $results = ClientCompany::query()
            ->where(fn ($q) => $q
                ->where('company_name', $operator, "%{$term}%")
                ->orWhere('company_code', $operator, "%{$term}%")
                ->orWhere('contact_person', $operator, "%{$term}%"))
            ->orderBy('company_name')
            ->limit(self::PER_TYPE_LIMIT)
            ->get()
            ->map(fn (ClientCompany $c) => [
                'id' => $c->id,
                'title' => $c->company_name,
                'subtitle' => implode(' · ', array_filter([$c->company_code, $c->business_type])),
                'badge' => ucfirst((string) $c->status),
                'url' => "/clients/{$c->id}",
            ]);

        return ['type' => 'clients', 'label' => 'Client companies', 'results' => $results->all()];
    }

    /**
     * PostgreSQL's LIKE is case-sensitive, so searching "santos" would miss
     * "Santos". ILIKE fixes that but does not exist in SQLite, which the test
     * suite uses.
     */
    private function likeOperator(): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql'
            ? 'ilike'
            : 'like';
    }
}
