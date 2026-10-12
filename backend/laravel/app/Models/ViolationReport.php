<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A coordinator's written-up incident, on its way to HR.
 *
 * Not a violation. It becomes one only when HR validates it, at which point an
 * EmployeeViolation is created that points back here. Until then nothing about
 * it counts towards the employee's disciplinary threshold.
 */
class ViolationReport extends Model
{
    /** Who can still change it: the coordinator, before HR has it, or after HR returns it. */
    public const EDITABLE = ['draft', 'returned'];

    /** Waiting on HR. */
    public const PENDING_REVIEW = ['submitted', 'under_review'];

    public const STATUSES = ['draft', 'submitted', 'under_review', 'returned', 'validated', 'dismissed'];

    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted to HR',
        'under_review' => 'Under HR review',
        'returned' => 'Returned for correction',
        'validated' => 'Validated - recorded as a violation',
        'dismissed' => 'Dismissed by HR',
    ];

    protected $fillable = [
        'report_code',
        'employee_id',
        'client_company_id',
        'client_department_id',
        'reported_by',
        'violation_type',
        'incident_date',
        'details',
        'notes',
        'evidence_path',
        'evidence_name',
        'status',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The official record HR created from this report, once validated. */
    public function violation(): HasOne
    {
        return $this->hasOne(EmployeeViolation::class, 'violation_report_id');
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
