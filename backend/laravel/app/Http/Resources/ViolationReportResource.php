<?php

namespace App\Http\Resources;

use App\Models\ViolationReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A violation report as both the coordinator and HR see it.
 *
 * The storage path never leaves the server; the screen is told whether evidence
 * exists and asks for a short-lived link when someone opens it.
 *
 * @mixin \App\Models\ViolationReport
 */
class ViolationReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'report_code' => $this->report_code,
            'status' => $this->status,
            'status_label' => ViolationReport::LABELS[$this->status] ?? $this->status,

            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'full_name' => $this->employee?->full_name,
                'employee_number' => $this->employee?->employee_number,
                'position' => $this->employee?->current_position_title,
                'employment_status' => $this->employee?->employment_status,
            ]),
            'client_company_id' => $this->client_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->department_name),

            'violation_type' => $this->violation_type,
            'type_label' => ucwords(str_replace('_', ' ', (string) $this->violation_type)),
            'incident_date' => $this->incident_date?->toDateString(),
            'details' => $this->details,
            'notes' => $this->notes,
            'has_evidence' => filled($this->evidence_path),
            'evidence_name' => $this->evidence_name,

            'reported_by' => $this->whenLoaded('reporter', fn () => [
                'id' => $this->reporter?->id,
                'name' => $this->reporter?->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->full_name),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,

            // The official record HR created from it, once validated.
            'violation' => $this->whenLoaded('violation', fn () => $this->violation ? [
                'id' => $this->violation->id,
                'status' => $this->violation->status,
                'penalty' => $this->violation->penalty,
                'violation_type' => $this->violation->violation_type,
                'is_active' => $this->violation->isActive(),
            ] : null),

            // What the person looking at it may do next.
            'can_edit' => $user?->isCoordinator()
                && (int) $this->reported_by === (int) $user->id
                && $this->resource->isEditable(),
            'can_discard' => $user?->isCoordinator()
                && (int) $this->reported_by === (int) $user->id
                && $this->status === 'draft',
            'can_review' => (bool) $user?->can('violation_reports.review') && $this->resource->isAwaitingReview(),
        ];
    }
}
