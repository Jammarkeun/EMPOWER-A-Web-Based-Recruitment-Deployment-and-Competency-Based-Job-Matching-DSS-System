<?php

namespace App\Services;

use App\Models\ClientCompany;
use App\Models\ClientDepartment;
use App\Models\CoordinatorAssignment;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\ManpowerRequisition;
use App\Models\ManpowerRequisitionStatusHistory;
use App\Models\User;
use App\Notifications\RequisitionReviewed;
use App\Notifications\RequisitionSubmitted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A coordinator's manpower requisition, from draft to fulfilled.
 *
 *   draft ──submit──▶ submitted ──▶ under_review ──▶ approved ──▶ fulfilled
 *     ▲                   │               │       └─▶ rejected
 *     └──── returned ◀────┴───────────────┘
 *
 * The coordinator writes and submits; HR reviews. Submitting approves nothing,
 * and nobody approves a requisition they filed. Approving creates the
 * requisition's manpower request in the same transaction (convert), linked
 * both ways and only ever once; the requisition itself is never rewritten.
 * Every status change writes a history row and an audit entry.
 *
 * Written on CDE's own Manpower Requisition Form (config
 * 'empower.requisition_form'). On that form the coordinator is "Requested by"
 * and the HR officer or administrator who approves it is "Acknowledged by".
 */
class ManpowerRequisitionService
{
    /** What the coordinator fills in on CDE's form, and nothing else. */
    public const FORM_FIELDS = [
        'date_requested', 'required_by',
        'workers_needed', 'male_count', 'female_count', 'job_position_id', 'position_title',
        'qualifications', 'other_remarks',
        'request_reason', 'request_reason_details',
        // EMPOWER's, not on CDE's form: which of the client's departments,
        // when the coordinator knows. HR needs one to record the request.
        'client_department_id',
    ];

    /** The reasons whose tick has something written beside it. */
    private const REASONS_WITH_DETAILS = ['replacement', 'others'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentStorageService $storage,
        private readonly ReferenceCodeService $codes,
        private readonly JobRequestFactory $factory,
    ) {
    }

    // ------------------------------------------------------------ coordinator

    public function create(CoordinatorAssignment $assignment, User $coordinator, array $data): ManpowerRequisition
    {
        $attributes = $this->normalise($assignment, $data, null);

        $requisition = DB::transaction(function () use ($assignment, $coordinator, $attributes) {
            $requisition = ManpowerRequisition::create($attributes + [
                'requisition_code' => $this->codes->requisition(),
                'form_version' => (string) config('empower.requisition_form.version'),
                'client_company_id' => $assignment->client_company_id,
                'requested_by' => $coordinator->id,
                'status' => 'draft',
            ]);

            $this->history($requisition, null, 'draft', $coordinator, null);

            return $requisition;
        });

        $this->audit->record('create', 'requisitions', ManpowerRequisition::class, $requisition->id, null, [
            'requisition_code' => $requisition->requisition_code,
            'position_title' => $requisition->position_title,
            'workers_needed' => $requisition->workers_needed,
            'request_reason' => $requisition->request_reason,
        ], clientCompanyId: $requisition->client_company_id);

        return $requisition->fresh();
    }

    /**
     * A draft, or one HR returned. One started on the temporary default form
     * is finished on CDE's form: it takes the current form version, and what
     * the temporary form recorded stays on it untouched.
     */
    public function update(ManpowerRequisition $requisition, CoordinatorAssignment $assignment, User $coordinator, array $data): ManpowerRequisition
    {
        $this->assertOwnEditable($requisition, $coordinator);

        $attributes = $this->normalise($assignment, $data, $requisition)
            + ['form_version' => (string) config('empower.requisition_form.version')];
        $before = $requisition->only(array_keys($attributes));
        $requisition->update($attributes);

        $this->audit->recordUpdate('requisitions', ManpowerRequisition::class, $requisition->id, $before, $attributes);

        return $requisition->fresh();
    }

    public function submit(ManpowerRequisition $requisition, User $coordinator): ManpowerRequisition
    {
        $this->assertOwnEditable($requisition, $coordinator);

        // Nothing reaches HR on the temporary form any more: a draft started
        // there is completed on CDE's form first, which means a reason ticked.
        if (blank($requisition->request_reason)) {
            throw ValidationException::withMessages([
                'request_reason' => ['Tick the Reason of Request before submitting.'],
            ]);
        }

        $from = $requisition->status;

        DB::transaction(function () use ($requisition, $coordinator, $from) {
            // A resubmission is new to HR again.
            $requisition->forceFill(['hr_viewed_at' => null, 'hr_viewed_by' => null]);
            $requisition->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->history($requisition, $from, 'submitted', $coordinator, null);
        });

        $this->audit->record('submit', 'requisitions', ManpowerRequisition::class, $requisition->id,
            ['status' => $from], ['status' => 'submitted'], clientCompanyId: $requisition->client_company_id);

        $requisition->load(['company', 'requester']);

        User::permission('requisitions.review')
            ->where('is_active', true)
            ->get()
            ->each->notify(new RequisitionSubmitted($requisition));

        return $requisition;
    }

    /** A draft that was never sent can be discarded. Anything HR has seen stays. */
    public function discard(ManpowerRequisition $requisition, User $coordinator): void
    {
        if ($requisition->status !== 'draft' || (int) $requisition->requested_by !== (int) $coordinator->id) {
            throw new RuntimeException('Only your own unsent draft can be discarded.', 403);
        }

        $this->audit->record('delete', 'requisitions', ManpowerRequisition::class, $requisition->id,
            ['requisition_code' => $requisition->requisition_code, 'status' => 'draft'], null,
            clientCompanyId: $requisition->client_company_id);

        if ($requisition->attachment_path) {
            $this->storage->delete($requisition->attachment_path);
        }

        $requisition->delete();
    }

    // -------------------------------------------------------------------- HR

    /**
     * HR's decision.
     *
     * Approving also creates the requisition's manpower request, in the same
     * transaction and under the same row lock: either the requisition is
     * approved and has its one request, or neither happened. HR does not type
     * the requisition into a second form.
     *
     * @param  string  $action  start_review | return | approve | reject | fulfil
     * @param  array{client_department_id?: int|null, job_position_id?: int|null, confirm_new_position?: bool}  $conversion
     *         What HR settles on approval when the form leaves it open; see convert().
     */
    public function review(ManpowerRequisition $requisition, User $reviewer, string $action, ?string $note, array $conversion = []): ManpowerRequisition
    {
        $rule = ManpowerRequisition::REVIEW_ACTIONS[$action] ?? null;

        if ($rule === null) {
            throw new RuntimeException('That is not something that can be done to a requisition.', 422);
        }

        // Coordinators never reach HR's routes, but the rule is the business
        // rule, not the route: nobody approves what they asked for.
        if ((int) $requisition->requested_by === (int) $reviewer->id) {
            throw new RuntimeException('You filed this requisition, so somebody else has to review it.', 403);
        }

        // Approving now records a manpower request, so it takes the right to
        // record one as well as the right to review.
        if ($action === 'approve' && ! $reviewer->can('job_requests.create')) {
            throw new RuntimeException('Approving creates a manpower request, which your role cannot record.', 403);
        }

        if (in_array($action, ['return', 'reject'], true) && blank($note)) {
            throw ValidationException::withMessages([
                'note' => ['Say why, so the coordinator knows what to do next.'],
            ]);
        }

        [$from, $jobRequest, $conversionLog] = $this->atomically(function () use ($requisition, $reviewer, $action, $rule, $note, $conversion) {
            $locked = ManpowerRequisition::query()->whereKey($requisition->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($from, $rule['from'], true)) {
                throw new RuntimeException(sprintf(
                    'This requisition is %s, so it cannot be %s.',
                    strtolower(ManpowerRequisition::LABELS[$from] ?? $from),
                    match ($action) {
                        'start_review' => 'put under review',
                        'return' => 'returned',
                        'approve' => 'approved',
                        'reject' => 'rejected',
                        'fulfil' => 'marked fulfilled',
                    }
                ), 409);
            }

            [$jobRequest, $conversionLog] = $action === 'approve'
                ? $this->convert($locked, $reviewer, $conversion)
                : [null, null];

            $locked->update(array_filter([
                'status' => $rule['to'],
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                // A later step without a note keeps the earlier explanation.
                'review_note' => filled($note) ? $note : $locked->review_note,
                'job_request_id' => $jobRequest?->id,
            ], fn ($value) => $value !== null));

            $this->history($locked, $from, $rule['to'], $reviewer, $note);

            return [$from, $jobRequest, $conversionLog];
        });

        $this->audit->record('review', 'requisitions', ManpowerRequisition::class, $requisition->id,
            ['status' => $from], array_filter(['status' => $rule['to'], 'note' => $note]),
            clientCompanyId: $requisition->client_company_id);

        if ($jobRequest) {
            $this->recordConversion($requisition, $jobRequest, $conversionLog, $reviewer);
        }

        $requisition->refresh()->load(['company', 'requester']);

        if ($requisition->requester?->is_active) {
            $requisition->requester->notify(new RequisitionReviewed($requisition));
        }

        return $requisition;
    }

    /**
     * Creates the manpower request for a requisition approved before approval
     * did so itself (and never recorded by hand). Same mapping, same lock,
     * same one-request rule as approval.
     */
    public function convertApproved(ManpowerRequisition $requisition, User $actor, array $conversion = []): JobRequest
    {
        if ((int) $requisition->requested_by === (int) $actor->id) {
            throw new RuntimeException('You filed this requisition, so somebody else has to record it.', 403);
        }

        [$jobRequest, $conversionLog] = $this->atomically(function () use ($requisition, $actor, $conversion) {
            $locked = ManpowerRequisition::query()->whereKey($requisition->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new RuntimeException('Only an approved requisition can be recorded as a manpower request.', 409);
            }

            [$jobRequest, $conversionLog] = $this->convert($locked, $actor, $conversion);
            $locked->update(['job_request_id' => $jobRequest->id]);

            return [$jobRequest, $conversionLog];
        });

        $this->recordConversion($requisition, $jobRequest, $conversionLog, $actor);

        return $jobRequest;
    }

    /**
     * What approving would still need from HR, for the approval screen: the
     * department when the coordinator named none, and the position when the
     * title is not one of the client's positions yet.
     */
    public function conversionNeeds(ManpowerRequisition $requisition): array
    {
        $match = $requisition->job_position_id || blank($requisition->position_title)
            ? null
            : $this->factory->findPosition((int) $requisition->client_company_id, $requisition->position_title);

        return [
            'needs_department' => ! $requisition->client_department_id,
            'needs_position' => ! $requisition->job_position_id && ! ($match?->isActive()),
            // A withdrawn position with the same title comes back into use
            // rather than being created twice.
            'matching_position' => $match ? [
                'id' => $match->id,
                'position_title' => $match->position_title,
                'status' => $match->status,
            ] : null,
        ];
    }

    /**
     * The requisition, as a manpower request.
     *
     * Only what the requisition actually says is carried over (see
     * requestAttributes). Two things the form can leave open are settled by
     * HR here, never guessed:
     *
     *   - the department, which is not on CDE's form at all; when the
     *     coordinator chose one it is used and cannot be swapped here - a
     *     wrong department is returned for correction instead;
     *   - the position, when the coordinator wrote a title that is not one of
     *     the client's positions: HR picks the position it means, or confirms
     *     it is a new one, which is only then added to the client's list.
     *
     * Must run inside the caller's transaction, with the requisition locked.
     *
     * @return array{0: JobRequest, 1: array<string, mixed>}
     */
    private function convert(ManpowerRequisition $locked, User $actor, array $conversion): array
    {
        if ($locked->job_request_id || JobRequest::withTrashed()->where('manpower_requisition_id', $locked->id)->exists()) {
            throw new RuntimeException('This requisition has already been recorded as a manpower request.', 409);
        }

        $company = ClientCompany::findOrFail($locked->client_company_id);

        if (! $company->isActive()) {
            throw new RuntimeException('This client company is inactive and cannot raise new manpower requests.', 409);
        }

        $departmentId = $this->conversionDepartment($locked, $conversion['client_department_id'] ?? null);
        [$position, $positionLog] = $this->conversionPosition($locked, $conversion);

        $jobRequest = $this->factory->create($company, $this->requestAttributes($locked) + [
            'client_department_id' => $departmentId,
        ] + $position, $actor);

        return [$jobRequest, [
            'department_set_by_hr' => ! $locked->client_department_id,
            'position' => $positionLog,
        ]];
    }

    private function conversionDepartment(ManpowerRequisition $locked, mixed $supplied): int
    {
        if ($locked->client_department_id) {
            if (filled($supplied) && (int) $supplied !== (int) $locked->client_department_id) {
                throw ValidationException::withMessages([
                    'client_department_id' => ['The coordinator named a different department. Return the requisition for correction to change it.'],
                ]);
            }

            return (int) $locked->client_department_id;
        }

        if (blank($supplied)) {
            throw ValidationException::withMessages([
                'client_department_id' => ['The requisition names no department. Choose the client department before approving.'],
            ]);
        }

        $department = ClientDepartment::query()
            ->whereKey((int) $supplied)
            ->where('client_company_id', $locked->client_company_id)
            ->where('status', 'active')
            ->first();

        if (! $department) {
            throw ValidationException::withMessages([
                'client_department_id' => ['Choose an active department of this client company.'],
            ]);
        }

        return $department->id;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function conversionPosition(ManpowerRequisition $locked, array $conversion): array
    {
        $supplied = $conversion['job_position_id'] ?? null;

        // Chosen from the client's list on the form: that is the position.
        if ($locked->job_position_id) {
            if (filled($supplied) && (int) $supplied !== (int) $locked->job_position_id) {
                throw ValidationException::withMessages([
                    'job_position_id' => ['The coordinator chose a different position. Return the requisition for correction to change it.'],
                ]);
            }

            return [['job_position_id' => $locked->job_position_id], 'from_requisition'];
        }

        // HR says which of the client's positions the written title means.
        if (filled($supplied)) {
            $position = JobPosition::query()
                ->whereKey((int) $supplied)
                ->where('status', 'active')
                ->where(fn ($q) => $q->where('client_company_id', $locked->client_company_id)->orWhereNull('client_company_id'))
                ->first();

            if (! $position) {
                throw ValidationException::withMessages([
                    'job_position_id' => ['Choose an active position of this client company.'],
                ]);
            }

            return [['job_position_id' => $position->id], 'mapped_by_hr'];
        }

        $existing = $this->factory->findPosition((int) $locked->client_company_id, $locked->position_title);

        if ($existing?->isActive()) {
            return [['job_position_id' => $existing->id], 'matched_title'];
        }

        // A new role, or a withdrawn one coming back, only on HR's say-so.
        if (! ($conversion['confirm_new_position'] ?? false)) {
            throw ValidationException::withMessages([
                'job_position_id' => [$existing
                    ? sprintf('"%s" is a withdrawn position of this client. Confirm it is wanted again, or choose another position.', $existing->position_title)
                    : sprintf('"%s" is not one of this client\'s positions. Choose the position it means, or confirm it is a new one.', $locked->position_title)],
            ]);
        }

        return [['position_title' => $locked->position_title], $existing ? 'reactivated' : 'new_position'];
    }

    /**
     * The requisition's own values, in the request's terms.
     *
     * Carried over as they are: the client, headcount, dates, and - as the
     * request's remarks - the reason, the qualifications and the remarks,
     * worded the way the form has them. Required Qualification(s) is free
     * text on CDE's form, so it is NOT split into education, experience, age
     * or physical requirements; those stay empty for HR to set as criteria,
     * and the original text is always read from the requisition itself.
     *
     * The only derived value is the gender preference, and only when the form
     * leaves no doubt: every worker asked for is male, or every one female.
     */
    private function requestAttributes(ManpowerRequisition $requisition): array
    {
        $workers = (int) $requisition->workers_needed;

        $gender = match (true) {
            $requisition->isProvisional() && in_array($requisition->gender_requirement, ['male', 'female'], true) => $requisition->gender_requirement,
            $workers > 0 && (int) $requisition->male_count === $workers => 'male',
            $workers > 0 && (int) $requisition->female_count === $workers => 'female',
            default => null,
        };

        return [
            'workers_needed' => $workers,
            'date_requested' => $requisition->date_requested?->toDateString(),
            'deployment_deadline' => $requisition->required_by?->toDateString(),
            'gender_preference' => $gender,
            'request_source' => 'Coordinator requisition '.$requisition->requisition_code,
            'manpower_requisition_id' => $requisition->id,
            'remarks' => $this->requestRemarks($requisition),
        ];
    }

    /** The form's words, as the request's remarks. */
    public function requestRemarks(ManpowerRequisition $r): ?string
    {
        if ($r->isProvisional()) {
            return $r->reason;
        }

        $split = array_filter([
            $r->male_count ? "{$r->male_count} male" : null,
            $r->female_count ? "{$r->female_count} female" : null,
        ]);
        $reason = config("empower.requisition_form.reasons.{$r->request_reason}", $r->request_reason);

        if ($reason && filled($r->request_reason_details)) {
            $reason .= ($r->request_reason === 'others' ? ': ' : ' of ').$r->request_reason_details;
        }

        return implode("\n", array_filter([
            sprintf('Total Manpower Needed: %d%s for %s', $r->workers_needed, $split ? ' ('.implode(', ', $split).')' : '', $r->position_title),
            $reason ? "Reason of Request: {$reason}" : null,
            filled($r->qualifications) ? "Required Qualification(s): {$r->qualifications}" : null,
            filled($r->other_remarks) ? "Other Remarks: {$r->other_remarks}" : null,
        ])) ?: null;
    }

    private function recordConversion(ManpowerRequisition $requisition, JobRequest $jobRequest, array $log, User $actor): void
    {
        $this->audit->record('create', 'job_requests', JobRequest::class, $jobRequest->id, null, [
            'request_code' => $jobRequest->request_code,
            'requisition' => $requisition->requisition_code,
            'position_title' => $jobRequest->position_title,
            'workers_needed' => $jobRequest->workers_needed,
            'client_department_id' => $jobRequest->client_department_id,
        ], clientCompanyId: $jobRequest->client_company_id, actor: $actor);

        $this->audit->record('link', 'requisitions', ManpowerRequisition::class, $requisition->id, null, array_merge([
            'job_request' => $jobRequest->request_code,
        ], $log), clientCompanyId: $requisition->client_company_id, actor: $actor);
    }

    /**
     * A transaction whose unique-index refusal - the last line of defence
     * against a second request for one requisition - reads as the refusal it
     * is rather than a server error.
     */
    private function atomically(callable $work): mixed
    {
        try {
            return DB::transaction($work);
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException('This requisition has already been recorded as a manpower request.', 409);
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * The attributes to write, checked against the coordinator's client and
     * CDE's form.
     *
     * Only the form's own fields are taken. What a requisition recorded on the
     * temporary default form (reason, employment type, schedule and so on) is
     * never written here, so it stays exactly as it was.
     */
    private function normalise(CoordinatorAssignment $assignment, array $data, ?ManpowerRequisition $existing): array
    {
        $companyId = (int) $assignment->client_company_id;
        $attributes = collect($data)->only(self::FORM_FIELDS)->all();

        if (array_key_exists('client_department_id', $attributes) && filled($attributes['client_department_id'])) {
            $inScope = ClientDepartment::query()
                ->whereKey((int) $attributes['client_department_id'])
                ->where('client_company_id', $companyId)
                ->where('status', 'active')
                ->exists();

            if (! $inScope) {
                throw new RuntimeException('That department is not an active department of your assigned client company.', 403);
            }
        }

        // A position from the client's list fixes the title, so the two never
        // disagree. An agency-wide position (no company) is allowed too.
        if (array_key_exists('job_position_id', $attributes) && filled($attributes['job_position_id'])) {
            $position = JobPosition::query()
                ->whereKey((int) $attributes['job_position_id'])
                ->where('status', 'active')
                ->where(fn ($q) => $q->where('client_company_id', $companyId)->orWhereNull('client_company_id'))
                ->first();

            if (! $position) {
                throw new RuntimeException('That position is not offered at your assigned client company.', 403);
            }

            $attributes['position_title'] = $position->position_title;
        }

        // Cross-field rules, judged on the values the record will end up with.
        $merged = array_merge($existing?->only([
            'date_requested', 'required_by', 'workers_needed', 'male_count', 'female_count',
            'request_reason', 'request_reason_details',
        ]) ?? [], $attributes);

        // Only "Replacement" (whom it replaces) and "Others: ____" have
        // anything written beside the tick. Ticking another box clears it.
        if (! in_array($merged['request_reason'] ?? null, self::REASONS_WITH_DETAILS, true)
            && (array_key_exists('request_reason', $attributes) || array_key_exists('request_reason_details', $attributes))) {
            $attributes['request_reason_details'] = $merged['request_reason_details'] = null;
        }

        if (blank($merged['request_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'request_reason' => ['Tick the Reason of Request.'],
            ]);
        }

        if ($merged['request_reason'] === 'others' && blank($merged['request_reason_details'] ?? null)) {
            throw ValidationException::withMessages([
                'request_reason_details' => ['Write the reason beside "Others".'],
            ]);
        }

        if (filled($merged['date_requested'] ?? null) && filled($merged['required_by'] ?? null)
            && Carbon::parse($merged['required_by'])->lt(Carbon::parse($merged['date_requested']))) {
            throw ValidationException::withMessages([
                'required_by' => ['The Date Needed cannot be before the Date requested.'],
            ]);
        }

        // How many men and how many women, when the client said (CDE, 26
        // October 2026). Whatever they leave unsaid can be either.
        if ((int) ($merged['male_count'] ?? 0) + (int) ($merged['female_count'] ?? 0) > (int) ($merged['workers_needed'] ?? 0)) {
            throw ValidationException::withMessages([
                'male_count' => ['The numbers of men and women add up to more than the Total Manpower Needed.'],
            ]);
        }

        return $attributes;
    }

    private function history(ManpowerRequisition $requisition, ?string $from, string $to, User $actor, ?string $note): void
    {
        ManpowerRequisitionStatusHistory::create([
            'manpower_requisition_id' => $requisition->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    private function assertOwnEditable(ManpowerRequisition $requisition, User $coordinator): void
    {
        if ((int) $requisition->requested_by !== (int) $coordinator->id) {
            throw new RuntimeException('Only the coordinator who wrote this requisition can change it.', 403);
        }

        if (! $requisition->isEditable()) {
            throw new RuntimeException('This requisition has been sent to HR and can no longer be changed.', 409);
        }
    }
}
