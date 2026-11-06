<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A client's DTR file, handed over to HR by the coordinator.
 *
 *   submitted ──HR opens it in the importer──▶ under_review ──import confirmed──▶ imported
 *       │                                         │   └──draft discarded──▶ submitted
 *       ├──────────── return (reason) ────────────┴──▶ returned ──coordinator sends a corrected file──▶ (new submission)
 *       └──────────── reject (reason) ────────────────▶ rejected
 *
 * A submission writes no attendance. Only the existing DTR importer does,
 * from an import draft opened from this file; "imported" is set by the
 * importer when that draft is confirmed, never by hand.
 */
class DtrSubmission extends Model
{
    public const STATUSES = ['submitted', 'under_review', 'returned', 'rejected', 'imported'];

    /** Still waiting on HR. */
    public const PENDING = ['submitted', 'under_review'];

    public const LABELS = [
        'submitted' => 'Submitted to HR',
        'under_review' => 'Under HR review',
        'returned' => 'Needs correction',
        'rejected' => 'Rejected',
        'imported' => 'Imported',
    ];

    protected $fillable = [
        'submission_code',
        'client_company_id',
        'period_from',
        'period_to',
        'source_note',
        'original_filename',
        'file_disk',
        'file_path',
        'file_hash',
        'file_size_bytes',
        'total_rows',
        'status',
        'resubmission_of_id',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'attendance_import_id',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'file_size_bytes' => 'integer',
            'total_rows' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The import that took this file in. */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class, 'attendance_import_id');
    }

    /** Every import draft opened from this file, discarded ones included. */
    public function imports(): HasMany
    {
        return $this->hasMany(AttendanceImport::class)->orderBy('id');
    }

    public function resubmissionOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resubmission_of_id');
    }

    /** The corrected file sent after this one was returned. */
    public function resubmission(): HasOne
    {
        return $this->hasOne(self::class, 'resubmission_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DtrSubmissionEvent::class)->orderBy('id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', self::PENDING);
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
