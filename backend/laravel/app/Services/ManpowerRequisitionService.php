<?php

namespace App\Services;

use App\Models\ClientDepartment;
use App\Models\CoordinatorAssignment;
use App\Models\JobPosition;
use App\Models\JobRequest;
use App\Models\ManpowerRequisition;
use App\Models\ManpowerRequisitionStatusHistory;
use App\Models\User;
use App\Notifications\RequisitionReviewed;
use App\Notifications\RequisitionSubmitted;
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
 * and nobody approves a requisition they filed. An approved requisition is
 * recorded as a manpower request through the ordinary request screen, which
 * links the two (linkJobRequest). Every status change writes a history row
 * and an audit entry.
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
     * @param  string  $action  start_review | return | approve | reject | fulfil
     */
    public function review(ManpowerRequisition $requisition, User $reviewer, string $action, ?string $note): ManpowerRequisition
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

        if (in_array($action, ['return', 'reject'], true) && blank($note)) {
            throw ValidationException::withMessages([
                'note' => ['Say why, so the coordinator knows what to do next.'],
            ]);
        }

        $from = DB::transaction(function () use ($requisition, $reviewer, $action, $rule, $note) {
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

            $locked->update([
                'status' => $rule['to'],
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                // A later step without a note keeps the earlier explanation.
                'review_note' => filled($note) ? $note : $locked->review_note,
            ]);

            $this->history($locked, $from, $rule['to'], $reviewer, $note);

            return $from;
        });

        $this->audit->record('review', 'requisitions', ManpowerRequisition::class, $requisition->id,
            ['status' => $from], array_filter(['status' => $rule['to'], 'note' => $note]),
            clientCompanyId: $requisition->client_company_id);

        $requisition->refresh()->load(['company', 'requester']);

        if ($requisition->requester?->is_active) {
            $requisition->requester->notify(new RequisitionReviewed($requisition));
        }

        return $requisition;
    }

    /**
     * Ties the manpower request HR recorded to the approved requisition it
     * came from. Called inside the request's own transaction, so either both
     * exist and point at each other or neither does.
     */
    public function linkJobRequest(ManpowerRequisition $requisition, JobRequest $jobRequest, User $actor): void
    {
        $locked = ManpowerRequisition::query()->whereKey($requisition->getKey())->lockForUpdate()->firstOrFail();

        $this->assertLinkable($locked, $jobRequest->client_company_id);

        $locked->update(['job_request_id' => $jobRequest->id]);

        $this->audit->record('link', 'requisitions', ManpowerRequisition::class, $locked->id, null, [
            'job_request' => $jobRequest->request_code,
        ], clientCompanyId: $locked->client_company_id, actor: $actor);
    }

    /** Refuses a requisition that cannot become this manpower request. */
    public function assertLinkable(ManpowerRequisition $requisition, int $companyId): void
    {
        if ($requisition->status !== 'approved') {
            throw new RuntimeException('Only an approved requisition can be recorded as a manpower request.', 409);
        }

        if ($requisition->job_request_id) {
            throw new RuntimeException('This requisition has already been recorded as a manpower request.', 409);
        }

        if ((int) $requisition->client_company_id !== $companyId) {
            throw new RuntimeException('The requisition is for a different client company.', 422);
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
