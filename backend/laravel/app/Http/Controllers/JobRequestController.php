<?php

namespace App\Http\Controllers;

use App\Http\Resources\JobRequestResource;
use App\Http\Resources\MatchResource;
use App\Http\Resources\RequestCriterionResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CompanyCriteria;
use App\Models\CriteriaCatalog;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\JobRequestMatch;
use App\Models\RequestCriteria;
use App\Services\AuditService;
use App\Services\Matching\CompetencyScoringService;
use App\Services\ReferenceCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobRequestController extends Controller
{
    public function __construct(
        private readonly ReferenceCodeService $codes,
        private readonly CompetencyScoringService $scoring,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JobRequest::class);

        $filters = $request->validate([
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['nullable', 'integer', 'exists:client_departments,id'],
            'request_status' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'only_open' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $requests = JobRequest::query()
            ->with(['company', 'department'])
            ->withCount(['criteria', 'matches'])
            ->when($filters['client_company_id'] ?? null, fn ($q, $v) => $q->where('client_company_id', $v))
            ->when($filters['client_department_id'] ?? null, fn ($q, $v) => $q->where('client_department_id', $v))
            ->when($filters['request_status'] ?? null, fn ($q, $v) => $q->where('request_status', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('date_requested', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('date_requested', '<=', $v))
            ->when($filters['only_open'] ?? false, fn ($q) => $q->open())
            ->orderByDesc('date_requested')
            ->paginate($filters['per_page'] ?? 20);

        return ApiResponse::paginated(JobRequestResource::collection($requests), 'Manpower requests retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', JobRequest::class);

        $data = $request->validate([
            'client_company_id' => ['required', 'integer', 'exists:client_companies,id'],
            'client_department_id' => ['required', 'integer', 'exists:client_departments,id'],
            // Either name the role or pick it from the company's list. Naming a
            // new one creates the position, which is what keeps the applicant's
            // dropdown in step with the work the agency actually has.
            'job_position_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            'position_title' => ['required_without:job_position_id', 'string', 'max:150'],
            'required_education' => ['nullable', 'string', 'max:150'],
            'required_experience_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'required_certifications' => ['nullable', 'string'],
            'gender_preference' => ['nullable', Rule::in(['any', 'male', 'female'])],
            'age_min' => ['nullable', 'integer', 'between:15,99'],
            'age_max' => ['nullable', 'integer', 'between:15,99', 'gte:age_min'],
            'height_min_cm' => ['nullable', 'numeric', 'between:100,250'],
            'physical_requirement' => ['nullable', 'string'],
            'availability_requirement' => ['nullable', 'string', 'max:120'],
            'workers_needed' => ['required', 'integer', 'min:1', 'max:1000'],
            'date_requested' => ['required', 'date'],
            'deployment_deadline' => ['nullable', 'date', 'after_or_equal:date_requested'],
            'request_source' => ['nullable', 'string', 'max:80'],
            'remarks' => ['nullable', 'string'],
        ]);

        $company = ClientCompany::findOrFail($data['client_company_id']);
        $department = ClientDepartment::findOrFail($data['client_department_id']);

        if (! $company->isActive()) {
            return ApiResponse::error('This client company is inactive and cannot raise new manpower requests.', 400);
        }

        if ($department->client_company_id !== $company->id) {
            return ApiResponse::error('The selected department does not belong to that client company.', 422);
        }

        $position = $this->resolvePosition($company, $data, $request->user()->id);

        $jobRequest = JobRequest::create(array_merge($data, [
            'request_code' => $this->codes->jobRequest(),
            'job_position_id' => $position->id,
            // Kept in step with the position when one was chosen, so the two
            // never disagree about what the job is called.
            'position_title' => $position->position_title,
            'created_by' => $request->user()->id,
        ]));

        $this->audit->record('create', 'job_requests', JobRequest::class, $jobRequest->id, null, $data);

        return ApiResponse::created(
            new JobRequestResource($jobRequest->load(['company', 'department'])),
            'Manpower request recorded'
        );
    }

    /**
     * The position this request is for, creating it if the title is a new one.
     *
     * The find-or-create is the mechanism that keeps the applicant's list of
     * positions honest without anyone having to maintain it as a separate
     * chore. A client asks for a role the agency has not placed before, HR
     * records the request in the ordinary way, and the role is on the
     * application form from that moment.
     *
     * Matching is case-insensitive so "Production Helper" typed one day and
     * "production helper" the next remain a single job rather than two.
     */
    private function resolvePosition(ClientCompany $company, array $data, int $actorId): JobPosition
    {
        if (! empty($data['job_position_id'])) {
            $position = JobPosition::findOrFail($data['job_position_id']);

            abort_if(
                ! is_null($position->client_company_id)
                    && (int) $position->client_company_id !== (int) $company->id,
                422,
                'That position belongs to a different client company.'
            );

            return $position;
        }

        $title = trim($data['position_title']);

        $existing = JobPosition::where('client_company_id', $company->id)
            ->whereRaw('LOWER(position_title) = ?', [mb_strtolower($title)])
            ->first();

        if ($existing) {
            // A role being asked for again is a role the agency still places,
            // so a previously withdrawn position comes back into use rather
            // than leaving the request pointing at something switched off.
            if (! $existing->isActive()) {
                $existing->update(['status' => 'active']);
            }

            return $existing;
        }

        return JobPosition::create([
            'position_code' => $this->codes->position(),
            'client_company_id' => $company->id,
            'position_title' => $title,
            'status' => 'active',
            'created_by' => $actorId,
        ]);
    }

    public function show(JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('view', $jobRequest);

        $jobRequest->load(['company', 'department', 'criteria.criterion'])
            ->loadCount(['matches']);

        return ApiResponse::success(new JobRequestResource($jobRequest));
    }

    public function update(Request $request, JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('update', $jobRequest);

        $data = $request->validate([
            'position_title' => ['sometimes', 'string', 'max:150'],
            'required_education' => ['nullable', 'string', 'max:150'],
            'required_experience_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'required_certifications' => ['nullable', 'string'],
            'gender_preference' => ['nullable', Rule::in(['any', 'male', 'female'])],
            'age_min' => ['nullable', 'integer', 'between:15,99'],
            'age_max' => ['nullable', 'integer', 'between:15,99', 'gte:age_min'],
            'height_min_cm' => ['nullable', 'numeric', 'between:100,250'],
            'physical_requirement' => ['nullable', 'string'],
            'availability_requirement' => ['nullable', 'string', 'max:120'],
            'workers_needed' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'deployment_deadline' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string'],
        ]);

        // Reducing the headcount below what has already been deployed would make
        // the remaining count negative and the request permanently inconsistent.
        if (isset($data['workers_needed']) && $data['workers_needed'] < $jobRequest->workers_fulfilled) {
            return ApiResponse::error(sprintf(
                'The request already has %d deployed worker(s), so the requirement cannot be reduced to %d.',
                $jobRequest->workers_fulfilled,
                $data['workers_needed']
            ), 400);
        }

        $before = $jobRequest->only(array_keys($data));
        $jobRequest->update($data);

        $this->audit->recordUpdate('job_requests', JobRequest::class, $jobRequest->id, $before, $data);

        return ApiResponse::success(new JobRequestResource($jobRequest->fresh()), 'Manpower request updated');
    }

    public function changeStatus(Request $request, JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('close', $jobRequest);

        $data = $request->validate([
            'request_status' => ['required', Rule::in(['open', 'in_progress', 'partially_fulfilled', 'fulfilled', 'closed', 'cancelled'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['request_status'] === 'fulfilled' && $jobRequest->workers_fulfilled < $jobRequest->workers_needed) {
            return ApiResponse::error(sprintf(
                'Only %d of %d positions are filled, so this request cannot be marked fulfilled. Use "closed" to end it early.',
                $jobRequest->workers_fulfilled,
                $jobRequest->workers_needed
            ), 400);
        }

        $before = ['request_status' => $jobRequest->request_status];
        $jobRequest->forceFill(['request_status' => $data['request_status']])->save();

        $this->audit->record(
            'update',
            'job_requests',
            JobRequest::class,
            $jobRequest->id,
            $before,
            ['request_status' => $data['request_status'], 'reason' => $data['reason'] ?? null]
        );

        return ApiResponse::success(new JobRequestResource($jobRequest->fresh()), 'Request status updated');
    }

    // ------------------------------------------------------------------ criteria

    public function criteria(JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('view', $jobRequest);

        $configured = $jobRequest->criteria()->with('criterion')->get();

        /*
         * A request with nothing set yet is offered the client's own standing
         * requirements as a starting point.
         *
         * Every request for the same client used to begin from a blank slate, so
         * whoever raised it had to remember what that client cares about — and
         * two officers would weight the same position differently. These are
         * suggestions, not rules: they are only proposed while the request has
         * no criteria of its own, and saving is still a deliberate act.
         */
        $suggested = [];

        if ($configured->isEmpty() && $jobRequest->client_company_id) {
            $suggested = CompanyCriteria::with('criterion')
                ->where('client_company_id', $jobRequest->client_company_id)
                ->get()
                ->map(fn (CompanyCriteria $c) => [
                    'criteria_code' => $c->criterion?->criteria_code,
                    'criteria_name' => $c->criterion?->criteria_name,
                    'mandatory_flag' => $c->mandatory_flag,
                    'weight_score' => (float) $c->weight_score,
                    'expected_value' => $c->expected_value,
                    'min_value' => $c->min_value !== null ? (float) $c->min_value : null,
                    'max_value' => $c->max_value !== null ? (float) $c->max_value : null,
                    'note' => $c->note,
                ])
                ->filter(fn ($row) => $row['criteria_code'] !== null)
                ->values()
                ->all();
        }

        [$fromRequest, $unmapped] = $configured->isEmpty()
            ? $this->criteriaFromStatedRequirements($jobRequest)
            : [[], []];

        return ApiResponse::success([
            'catalog' => $this->catalogue(),
            'configured' => RequestCriterionResource::collection($configured),
            'suggested_from_company' => $suggested,
            'suggested_from_request' => $fromRequest,
            'suggested' => $this->mergeSuggestions($suggested, $fromRequest),
            'unmapped_requirements' => $unmapped,
            'company_name' => $jobRequest->company?->company_name,
        ]);
    }

    /**
     * The requirements typed on the request itself, as criteria the engine can
     * score.
     *
     * The request form asks for the client's age bracket, minimum height,
     * required education and experience, gender preference, and certificates.
     * None of that used to reach the ranking: the engine scores only the
     * criteria configured on the request, so a client's "18 to 35, at least
     * 160 cm" sat in the details tab while applicants outside it were ranked
     * as though it had never been said. These are proposed, not applied —
     * saving the criteria is still a deliberate act by a person.
     *
     * @return array{0: array, 1: array} the suggestions, and the stated
     *                                   requirements that could not be read
     */
    private function criteriaFromStatedRequirements(JobRequest $jobRequest): array
    {
        $rows = [];
        $unmapped = [];

        if ($jobRequest->age_min !== null || $jobRequest->age_max !== null) {
            $rows[] = $this->suggestion('age', [
                'mandatory_flag' => true,
                'min_value' => $jobRequest->age_min,
                'max_value' => $jobRequest->age_max,
            ]);
        }

        if ($jobRequest->height_min_cm !== null) {
            $rows[] = $this->suggestion('height', [
                'mandatory_flag' => true,
                'min_value' => (float) $jobRequest->height_min_cm,
            ]);
        }

        if (filled($jobRequest->gender_preference) && $jobRequest->gender_preference !== 'any') {
            $rows[] = $this->suggestion('gender', [
                'mandatory_flag' => true,
                'expected_value' => $jobRequest->gender_preference,
            ]);
        }

        if (filled($jobRequest->required_education)) {
            $level = CompetencyScoringService::normaliseEducation($jobRequest->required_education);

            if ($level) {
                $rows[] = $this->suggestion('education', ['expected_value' => $level]);
            } else {
                $unmapped[] = sprintf(
                    'Required education "%s" does not match a level the engine recognises. Choose the level by hand.',
                    $jobRequest->required_education
                );
            }
        }

        if ($jobRequest->required_experience_months) {
            $rows[] = $this->suggestion('experience', [
                'min_value' => (float) $jobRequest->required_experience_months,
            ]);
        }

        if (filled($jobRequest->required_certifications)) {
            $rows[] = $this->suggestion('certifications', [
                'expected_value' => $jobRequest->required_certifications,
            ]);
        }

        if (filled($jobRequest->physical_requirement)) {
            $unmapped[] = sprintf(
                'Physical requirement "%s" is free text and is not scored. Check it at the interview.',
                $jobRequest->physical_requirement
            );
        }

        return [array_values(array_filter($rows)), $unmapped];
    }

    private function suggestion(string $code, array $values): ?array
    {
        $criterion = CriteriaCatalog::active()->where('criteria_code', $code)->first();

        if (! $criterion) {
            return null;
        }

        return array_merge([
            'criteria_code' => $code,
            'criteria_name' => $criterion->criteria_name,
            'mandatory_flag' => false,
            'weight_score' => 0.0,
            'expected_value' => null,
            'min_value' => null,
            'max_value' => null,
            'note' => 'Stated on this request.',
        ], $values);
    }

    /**
     * One starting point from both sources.
     *
     * The client's standing criteria supply the weights, since they describe
     * how much this client cares about each factor; the request supplies the
     * values, since they are what this particular posting asks for. Where only
     * one source mentions a criterion it is taken as it is.
     */
    private function mergeSuggestions(array $fromCompany, array $fromRequest): array
    {
        $merged = [];

        foreach ($fromCompany as $row) {
            $merged[$row['criteria_code']] = $row + ['sources' => ['company']];
        }

        foreach ($fromRequest as $row) {
            $code = $row['criteria_code'];

            if (! isset($merged[$code])) {
                $merged[$code] = $row + ['sources' => ['request']];

                continue;
            }

            foreach (['expected_value', 'min_value', 'max_value'] as $field) {
                if ($row[$field] !== null) {
                    $merged[$code][$field] = $row[$field];
                }
            }

            $merged[$code]['mandatory_flag'] = $merged[$code]['mandatory_flag'] || $row['mandatory_flag'];
            $merged[$code]['sources'][] = 'request';
        }

        return array_values($merged);
    }

    /**
     * The criteria catalogue, with what each one's expected value accepts.
     *
     * `accepts` is the shape of the answer, not a suggestion: `list` criteria -
     * skills and certifications - hold whatever competencies this particular
     * client requires, and holding any one of them satisfies the criterion.
     * `choice` criteria have a fixed vocabulary the scoring engine recognises,
     * published here so the form offers it rather than leaving an officer to
     * guess the spelling of "college_graduate".
     *
     * Getting that wrong is not a validation error, which is exactly why it
     * needs solving in the form: an unrecognised value simply never matches, so
     * the criterion silently scores nobody and explains nothing.
     */
    private function catalogue()
    {
        return CriteriaCatalog::active()
            ->orderBy('criteria_name')
            ->get()
            ->map(function (CriteriaCatalog $criterion) {
                $options = CompetencyScoringService::acceptedValues($criterion->criteria_code);

                return array_merge($criterion->toArray(), [
                    'accepts' => match (true) {
                        $options !== null => 'choice',
                        $criterion->value_type === 'text' => 'list',
                        default => 'none',
                    },
                    'options' => $options,
                    // For lists: whether every item is required and graded by
                    // the share held, or any one item is enough.
                    'list_match' => CompetencyScoringService::listMode($criterion->criteria_type),
                ]);
            });
    }

    /**
     * Replace the competency criteria and weights for a request.
     */
    public function setCriteria(Request $request, JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('configureCriteria', $jobRequest);

        $data = $request->validate([
            'criteria' => ['required', 'array', 'min:1'],
            'criteria.*.criteria_code' => ['required', 'string', 'exists:criteria_catalog,criteria_code'],
            'criteria.*.mandatory_flag' => ['nullable', 'boolean'],
            'criteria.*.weight_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'criteria.*.expected_value' => ['nullable', 'string', 'max:255'],
            'criteria.*.min_value' => ['nullable', 'numeric'],
            'criteria.*.max_value' => ['nullable', 'numeric', 'gte:criteria.*.min_value'],
            'criteria.*.rubric_json' => ['nullable', 'array'],
        ]);

        $catalog = CriteriaCatalog::active()->get()->keyBy('criteria_code');

        // At least one scored criterion must carry weight, otherwise every
        // candidate scores zero and the ranking is meaningless.
        $weighted = collect($data['criteria'])
            ->reject(fn ($c) => ($c['mandatory_flag'] ?? false)
                || ($catalog[$c['criteria_code']]->criteria_type ?? '') === 'hard_filter')
            ->sum(fn ($c) => (float) ($c['weight_score'] ?? 0));

        if ($weighted <= 0) {
            return ApiResponse::error(
                'At least one non-mandatory criterion must carry a weight above zero, otherwise every applicant scores zero.',
                422
            );
        }

        DB::transaction(function () use ($jobRequest, $data, $catalog, $request) {
            $jobRequest->criteria()->delete();

            foreach ($data['criteria'] as $criterion) {
                RequestCriteria::create([
                    'job_request_id' => $jobRequest->id,
                    'criteria_id' => $catalog[$criterion['criteria_code']]->id,
                    'mandatory_flag' => $criterion['mandatory_flag'] ?? false,
                    'weight_score' => $criterion['weight_score'] ?? 0,
                    'expected_value' => $criterion['expected_value'] ?? null,
                    'min_value' => $criterion['min_value'] ?? null,
                    'max_value' => $criterion['max_value'] ?? null,
                    'rubric_json' => $criterion['rubric_json'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        $this->audit->record(
            'update',
            'job_requests',
            JobRequest::class,
            $jobRequest->id,
            null,
            ['criteria_configured' => count($data['criteria']), 'total_weight' => $weighted]
        );

        return ApiResponse::success(
            RequestCriterionResource::collection($jobRequest->criteria()->with('criterion')->get()),
            'Competency criteria saved'
        );
    }

    // ------------------------------------------------------------------ matching

    /**
     * Run the competency evaluation. This ranks and recommends only; it never
     * deploys anyone.
     */
    public function evaluate(Request $request, JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('evaluate', $jobRequest);

        $options = $request->validate([
            'candidate_scope' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'between:1,500'],
        ]);

        $scores = $this->scoring->evaluate($jobRequest, $request->user(), $options);

        return ApiResponse::success([
            'job_request_id' => $jobRequest->id,
            'evaluated_candidates' => count($scores),
            'qualified_candidates' => count(array_filter($scores, fn ($s) => $s->passedAllGates)),
            'ranked' => MatchResource::collection($this->orderedMatches($jobRequest)),
            'notice' => 'These are recommendations. Deployment requires explicit HR action.',
        ], 'Evaluation completed');
    }

    public function rankings(Request $request, JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('view', $jobRequest);

        $filters = $request->validate([
            'only_qualified' => ['nullable', 'boolean'],
            'only_shortlisted' => ['nullable', 'boolean'],
            'recommendation_level' => ['nullable', 'string'],
        ]);

        $matches = $this->orderedMatches($jobRequest, function ($q) use ($filters) {
            $q->when($filters['only_qualified'] ?? false, fn ($q) => $q->qualified())
                ->when($filters['only_shortlisted'] ?? false, fn ($q) => $q->shortlisted())
                ->when($filters['recommendation_level'] ?? null, fn ($q, $v) => $q->where('recommendation_level', $v));
        });

        return ApiResponse::success(MatchResource::collection($matches));
    }

    /**
     * The request's results, ranked first and kept history after.
     *
     * Ordered with the unranked rows last on every database: PostgreSQL sorts
     * nulls first in ascending order where MySQL and SQLite sort them last, so
     * leaving it to the engine would put the history at the top of the list on
     * one platform and the bottom on the others.
     */
    private function orderedMatches(JobRequest $jobRequest, ?\Closure $filter = null)
    {
        return $jobRequest->matches()
            ->reorder()
            ->with('applicant')
            ->when($filter, $filter)
            ->orderByRaw('CASE WHEN rank_order IS NULL THEN 1 ELSE 0 END')
            ->orderBy('rank_order')
            ->get()
            // The request is already in hand; attaching it saves a query per
            // row when each result is compared with the position applied for.
            ->each(fn (JobRequestMatch $match) => $match->setRelation('jobRequest', $jobRequest));
    }

    /**
     * Mark candidates for client endorsement. Still not a hiring decision.
     */
    public function shortlist(Request $request, JobRequest $jobRequest): JsonResponse
    {
        $this->authorize('shortlist', $jobRequest);

        $data = $request->validate([
            'applicant_ids' => ['required', 'array', 'min:1'],
            'applicant_ids.*' => ['integer', 'exists:applicants,id'],
        ]);

        $updated = JobRequestMatch::where('job_request_id', $jobRequest->id)
            ->whereIn('applicant_id', $data['applicant_ids'])
            ->update([
                'is_shortlisted' => true,
                'shortlisted_at' => now(),
                'shortlisted_by' => $request->user()->id,
            ]);

        $this->audit->record(
            'update',
            'matching',
            JobRequest::class,
            $jobRequest->id,
            null,
            ['shortlisted_applicants' => $data['applicant_ids']]
        );

        return ApiResponse::success(
            ['shortlisted' => $updated],
            'Shortlist updated. Deployment still requires a separate, explicit action.'
        );
    }
}
