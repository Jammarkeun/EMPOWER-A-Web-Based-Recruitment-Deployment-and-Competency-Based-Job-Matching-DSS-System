<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's request for people, written up by the coordinator on CDE's
 * Manpower Requisition Form. Those filed before 29 October 2026 were written
 * on a temporary default form and say so in `form_version`.
 *
 * Not a manpower request. HR reviews it; an approved one is recorded as a
 * JobRequest through the ordinary screen, which links back here. Submitting
 * approves nothing, and a coordinator can never approve their own.
 */
class ManpowerRequisition extends Model
{
    /** The coordinator can still change it: before HR has it, or after HR returns it. */
    public const EDITABLE = ['draft', 'returned'];

    /** Waiting on HR. */
    public const PENDING_REVIEW = ['submitted', 'under_review'];

    public const STATUSES = ['draft', 'submitted', 'under_review', 'returned', 'approved', 'rejected', 'fulfilled'];

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted to HR',
        'under_review' => 'Under HR review',
        'returned' => 'Returned for correction',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'fulfilled' => 'Fulfilled',
    ];

    /**
     * What HR may do from each status, and where it leads. Submission is the
     * coordinator's and is not here.
     */
    public const REVIEW_ACTIONS = [
        'start_review' => ['from' => ['submitted'], 'to' => 'under_review'],
        'return' => ['from' => ['submitted', 'under_review'], 'to' => 'returned'],
        'approve' => ['from' => ['submitted', 'under_review'], 'to' => 'approved'],
        'reject' => ['from' => ['submitted', 'under_review'], 'to' => 'rejected'],
        'fulfil' => ['from' => ['approved'], 'to' => 'fulfilled'],
    ];

    protected $fillable = [
        'requisition_code',
        'form_version',
        'client_company_id',
        'client_department_id',
        'job_position_id',
        'position_title',
        'workers_needed',
        'date_requested',
        'required_by',
        'reason',
        'request_reason',
        'request_reason_details',
        'other_remarks',
        'gender_requirement',
        'male_count',
        'female_count',
        'employment_type',
        'work_location',
        'qualifications',
        'work_schedule',
        'compensation_notes',
        'additional_instructions',
        'client_requester_name',
        'attachment_path',
        'attachment_name',
        'status',
        'requested_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'job_request_id',
    ];

    protected function casts(): array
    {
        return [
            'date_requested' => 'date:Y-m-d',
            'required_by' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'workers_needed' => 'integer',
            'male_count' => 'integer',
            'female_count' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    /** Every status change, oldest first. */
    public function history(): HasMany
    {
        return $this->hasMany(ManpowerRequisitionStatusHistory::class)->orderBy('changed_at')->orderBy('id');
    }

    /** Written on the temporary default form, before CDE's own arrived. */
    public function isProvisional(): bool
    {
        return str_starts_with((string) $this->form_version, 'provisional');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isAwaitingReview(): bool
    {
        return in_array($this->status, self::PENDING_REVIEW, true);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('client_company_id', $companyId);
    }
}
