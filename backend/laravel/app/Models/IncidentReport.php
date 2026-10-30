<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something that happened at the client's site, reported by the coordinator
 * for the office to read.
 *
 * Not a violation and not about one worker: it has no employee, and nothing
 * here counts towards anybody's disciplinary record. A matter of one worker's
 * conduct is a ViolationReport.
 */
class IncidentReport extends Model
{
    /** The coordinator can still change it: only before it is sent. */
    public const EDITABLE = ['draft'];

    /** Sent, and not yet closed by management. */
    public const OPEN = ['submitted', 'under_review'];

    public const STATUSES = ['draft', 'submitted', 'under_review', 'resolved'];

    /** Worded as CDE put it on 26 October 2026: "case pending", "case resolved". */
    public const LABELS = [
        'draft' => 'Draft',
        'submitted' => 'Submitted - not yet read',
        'under_review' => 'Case pending',
        'resolved' => 'Case resolved',
    ];

    protected $fillable = [
        'report_code',
        'client_company_id',
        'client_department_id',
        'reported_by',
        'title',
        'incident_date',
        'incident_time',
        'location',
        'details',
        'evidence_path',
        'evidence_name',
        'status',
        'submitted_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The thread, oldest first, so it reads as it happened. */
    public function notes(): HasMany
    {
        return $this->hasMany(IncidentReportNote::class)->orderBy('created_at')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('client_company_id', $companyId);
    }
}
