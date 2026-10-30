<?php

namespace App\Http\Resources;

use App\Models\IncidentReport;
use App\Models\IncidentReportNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An incident report as both the coordinator and the office see it.
 *
 * The storage path never leaves the server; the screen is told whether
 * evidence exists and asks for a short-lived link when someone opens it.
 *
 * @mixin \App\Models\IncidentReport
 */
class IncidentReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isCoordinator = (bool) $user?->isCoordinator();

        return [
            'id' => $this->id,
            'report_code' => $this->report_code,
            'status' => $this->status,
            'status_label' => IncidentReport::LABELS[$this->status] ?? $this->status,

            'client_company_id' => $this->client_company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'client_department_id' => $this->client_department_id,
            'department_name' => $this->whenLoaded('department', fn () => $this->department?->department_name),

            'title' => $this->title,
            'incident_date' => $this->incident_date?->toDateString(),
            'incident_time' => $this->incident_time ? substr((string) $this->incident_time, 0, 5) : null,
            'location' => $this->location,
            'details' => $this->details,
            'has_evidence' => filled($this->evidence_path),
            'evidence_name' => $this->evidence_name,

            'reported_by' => $this->whenLoaded('reporter', fn () => [
                'id' => $this->reporter?->id,
                'name' => $this->reporter?->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->full_name),

            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn (IncidentReportNote $n) => [
                'id' => $n->id,
                'note' => $n->note,
                'author' => $n->author?->full_name,
                'author_role' => $n->author_role,
                'status_from' => $n->status_from,
                'status_to' => $n->status_to,
                'status_to_label' => $n->status_to ? (IncidentReport::LABELS[$n->status_to] ?? $n->status_to) : null,
                'created_at' => $n->created_at?->toIso8601String(),
            ])),
            'notes_count' => $this->whenCounted('notes'),

            // What the person looking at it may do next.
            'can_edit' => $isCoordinator
                && (int) $this->reported_by === (int) $user->id
                && $this->resource->isEditable(),
            'can_discard' => $isCoordinator
                && (int) $this->reported_by === (int) $user->id
                && $this->status === 'draft',
            'can_add_note' => $this->status !== 'draft'
                && ($isCoordinator || (bool) $user?->can('incident_reports.review')),
            'can_change_status' => ! $isCoordinator
                && (bool) $user?->can('incident_reports.review')
                && $this->status !== 'draft',
        ];
    }
}
