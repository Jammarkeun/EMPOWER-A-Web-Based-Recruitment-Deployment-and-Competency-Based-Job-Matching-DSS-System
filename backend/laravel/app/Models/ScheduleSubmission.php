<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work schedule a coordinator proposes for one worker, for HR to approve.
 *
 *   draft ──submit──▶ submitted ──approve──▶ approved (an employee schedule)
 *     │                   │
 *     │                   └──return──▶ returned ──submit──▶ submitted …
 *     └──────────── withdraw (draft, submitted or returned) ──▶ withdrawn
 *
 * Nothing here is compared with the DTR. Approval creates the EmployeeSchedule
 * that is, through AttendanceService::setSchedule - the same path HR's own
 * entry takes - and links it here; the submission is then closed for good.
 */
class ScheduleSubmission extends Model
{
    public const STATUSES = ['draft', 'submitted', 'returned', 'approved', 'withdrawn'];

    /** Still the coordinator's to change. */
    public const EDITABLE = ['draft', 'returned'];

    /** Not yet decided: at most one of these per employee at a time. */
    public const OPEN = ['draft', 'submitted', 'returned'];

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Pending HR review',
        'returned' => 'Returned for correction',
        'approved' => 'Approved',
        'withdrawn' => 'Withdrawn',
    ];

    /** The fields that make up the schedule itself. */
    public const SCHEDULE_FIELDS = [
        'effective_from', 'effective_to', 'work_days', 'shift_start', 'shift_end',
        'break_start', 'break_minutes', 'remarks', 'source_note',
    ];

    protected $fillable = [
        'submission_code',
        'employee_id',
        'deployment_id',
        'client_company_id',
        'client_department_id',
        'effective_from',
        'effective_to',
        'work_days',
        'shift_start',
        'shift_end',
        'break_start',
        'break_minutes',
        'remarks',
        'source_note',
        'status',
        'replaces_schedule_id',
        'employee_schedule_id',
        'created_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'work_days' => 'array',
            'break_minutes' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(EmployeeSchedule::class, 'replaces_schedule_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(EmployeeSchedule::class, 'employee_schedule_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScheduleSubmissionEvent::class)->orderBy('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /**
     * The proposal as an unsaved schedule, so it is described, and its night
     * shift recognised, exactly as an approved one would be.
     */
    public function toSchedule(): EmployeeSchedule
    {
        return new EmployeeSchedule($this->only([
            'employee_id', 'deployment_id', 'client_company_id', 'client_department_id',
            'effective_from', 'effective_to', 'work_days', 'shift_start', 'shift_end',
            'break_start', 'break_minutes', 'remarks',
        ]) + ['status' => 'active']);
    }
}
