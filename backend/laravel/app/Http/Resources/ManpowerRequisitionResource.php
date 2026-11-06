<?php

namespace App\Http\Resources;

use App\Models\ManpowerRequisition;
use App\Models\ManpowerRequisitionStatusHistory;
use App\Services\ManpowerRequisitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A manpower requisition as both the coordinator and HR see it.
 *
 * `form_version` and `provisional` travel with every record so the screen can
 * say which form it was written on: CDE's own, or - for those filed before 29
 * October 2026 - the temporary default, whose fields are still sent so what
 * those records hold stays readable.
 *
 * @mixin \App\Models\ManpowerRequisition
 */
class ManpowerRequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isCoordinator = (bool) $user?->isCoordinator();
        $reasons = config('empower.requisition_form.reasons', []);
        $genders = config('empower.requisition_form.temporary_form.gender_requirements', []);
        $types = config('empower.requisition_form.temporary_form.employment_types', []);
        $canReview = ! $isCoordinator
            && (bool) $user?->can('requisitions.review')
            && (int) $this->requested_by !== (int) $user?->id;
        // Approving creates the manpower request, so it needs both rights.
        $canRecord = $canReview && (bool) $user?->can('job_requests.create');
        $canConvert = $canRecord && $this->status === 'approved' && ! $this->job_request_id;

        return [
            'id' => $this->id,
            'requisition_code' => $this->requisition_code,
            'form_version' => $this->form_version,
            'provisional' => $this->resource->isProvisional(),
            'status' => $this->status,
            'status_label' => ManpowerRequisition::LABELS[$this->status] ?? $this->status,

            'client_company_id' => $this->client_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'client_department_id' => $this->client_department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->department_name),

            // CDE's form, in its order.
            'date_requested' => $this->date_requested?->toDateString(),
            'required_by' => $this->required_by?->toDateString(),
            'workers_needed' => $this->workers_needed,
            'male_count' => $this->male_count,
            'female_count' => $this->female_count,
            'job_position_id' => $this->job_position_id,
            'position_title' => $this->position_title,
            'qualifications' => $this->qualifications,
            'other_remarks' => $this->other_remarks,
            'request_reason' => $this->request_reason,
            'request_reason_label' => $this->request_reason ? ($reasons[$this->request_reason] ?? $this->request_reason) : null,
            'request_reason_details' => $this->request_reason_details,

            // The temporary default form's own fields, for what it recorded.
            'reason' => $this->reason,
            'gender_requirement' => $this->gender_requirement,
            'gender_requirement_label' => $this->gender_requirement ? ($genders[$this->gender_requirement] ?? $this->gender_requirement) : null,
            'employment_type' => $this->employment_type,
            'employment_type_label' => $this->employment_type ? ($types[$this->employment_type] ?? $this->employment_type) : null,
            'work_location' => $this->work_location,
            'work_schedule' => $this->work_schedule,
            'compensation_notes' => $this->compensation_notes,
            'additional_instructions' => $this->additional_instructions,
            'client_requester_name' => $this->client_requester_name,
            'has_attachment' => filled($this->attachment_path),
            'attachment_name' => $this->attachment_name,

            // "Requested by" on CDE's form: the coordinator who filed it.
            'requested_by' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester?->id,
                'name' => $this->requester?->full_name,
            ]),
            // "Acknowledged by": whoever approved it at CDE, read from the
            // history because a later step (fulfilled) moves reviewed_by on.
            'acknowledged_by' => $this->whenLoaded('history', function () {
                $approval = $this->history->last(fn (ManpowerRequisitionStatusHistory $h) => $h->to_status === 'approved');

                return $approval ? [
                    'name' => $approval->changedBy?->full_name,
                    'at' => $approval->changed_at?->toIso8601String(),
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'hr_viewed_at' => $this->hr_viewed_at?->toIso8601String(),
            // Submitted and not yet opened by anyone at HR: the list's dot.
            'is_new' => ! $isCoordinator && $this->status === 'submitted' && ! $this->hr_viewed_at,
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->full_name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,

            'job_request' => $this->whenLoaded('jobRequest', fn () => $this->jobRequest ? [
                'id' => $this->jobRequest->id,
                'request_code' => $this->jobRequest->request_code,
                'request_status' => $this->jobRequest->request_status,
                'position_title' => $this->jobRequest->position_title,
                'workers_needed' => $this->jobRequest->workers_needed,
                'workers_fulfilled' => $this->jobRequest->workers_fulfilled,
                'department_name' => $this->jobRequest->relationLoaded('department') ? $this->jobRequest->department?->department_name : null,
                // The coordinator named no department, so HR chose it on approval.
                'department_set_by_hr' => ! $this->client_department_id,
                'created_by' => $this->jobRequest->relationLoaded('creator') ? $this->jobRequest->creator?->full_name : null,
                'created_at' => $this->jobRequest->created_at?->toIso8601String(),
            ] : null),

            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn (ManpowerRequisitionStatusHistory $h) => [
                'id' => $h->id,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'to_label' => ManpowerRequisition::LABELS[$h->to_status] ?? $h->to_status,
                'note' => $h->note,
                'changed_by' => $h->changedBy?->full_name,
                'changed_at' => $h->changed_at?->toIso8601String(),
            ])),

            // What the person looking at it may do next. HR never sees an
            // action on a requisition they filed themselves.
            'can_edit' => $isCoordinator
                && (int) $this->requested_by === (int) $user->id
                && $this->resource->isEditable(),
            'can_discard' => $isCoordinator
                && (int) $this->requested_by === (int) $user->id
                && $this->status === 'draft',
            'review_actions' => ! $canReview
                ? []
                : collect(ManpowerRequisition::REVIEW_ACTIONS)
                    ->filter(fn ($rule) => in_array($this->status, $rule['from'], true))
                    ->keys()
                    ->reject(fn ($action) => $action === 'approve' && ! $canRecord)
                    ->values(),
            // Approved before approval created the request, and never recorded.
            'can_convert' => $canConvert,
            // What approving (or converting) still needs HR to settle. Only on
            // the single view, where history is loaded: it costs a query.
            'conversion' => $this->when(
                $this->relationLoaded('history') && ($canConvert || ($canRecord && $this->resource->isAwaitingReview())),
                fn () => app(ManpowerRequisitionService::class)->conversionNeeds($this->resource)
            ),
        ];
    }
}
