<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AttendanceDtrPeriod;
use App\Models\Employee;
use App\Services\DtrPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * DTR periods: whether a client's DTR for a run of days has arrived in full.
 *
 * Files are filed under a period when uploaded (DtrImportService). Here HR
 * sees what each period holds - the files, who was placed, how much of each
 * one's DTR is on file - and says whether the submission is incomplete or
 * complete. Staff who manage attendance only; coordinators are refused like
 * every other attendance endpoint.
 */
class DtrPeriodController extends Controller
{
    public function __construct(private readonly DtrPeriodService $periods)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'status' => ['nullable', Rule::in(array_keys(AttendanceDtrPeriod::LABELS))],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $periods = AttendanceDtrPeriod::query()
            ->with(['company', 'decider', 'imports'])
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // A period whose every file was discarded, and which HR never
            // decided, holds nothing to look at.
            ->where(fn ($q) => $q->whereIn('status', AttendanceDtrPeriod::DECIDED)
                ->orWhereHas('imports', fn ($i) => $i->where('status', '!=', 'discarded')))
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(
            $periods->through(fn (AttendanceDtrPeriod $p) => $this->periods->present($p)),
            'DTR periods'
        );
    }

    public function show(AttendanceDtrPeriod $period): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        return ApiResponse::success($this->periods->present($period->load(['company', 'decider', 'imports.uploader']), detailed: true));
    }

    public function decide(Request $request, AttendanceDtrPeriod $period): JsonResponse
    {
        $this->authorize('manageAnyAttendance', Employee::class);

        $data = $request->validate([
            'action' => ['required', Rule::in(['incomplete', 'complete', 'reopen'])],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:action,incomplete', 'required_if:action,reopen'],
            'acknowledge_gaps' => ['nullable', 'boolean'],
        ], [
            'note.required_if' => 'A note is needed: say what is missing, or why the period is being reopened.',
        ]);

        $period = $this->periods->decide($period, $data['action'], $data['note'] ?? null, (bool) ($data['acknowledge_gaps'] ?? false), $request->user());

        return ApiResponse::success(
            $this->periods->present($period->fresh()->load(['company', 'decider', 'imports.uploader']), detailed: true),
            match ($data['action']) {
                'complete' => 'DTR confirmed complete for the period',
                'incomplete' => 'Marked as an incomplete submission',
                default => 'Period reopened',
            }
        );
    }
}
