<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR's review of one day the comparison flagged.
 *
 * A flagged day with no review yet is "For review"; the row is created the
 * first time somebody acts on it. Verifying a day says the difference was
 * real; it does not record a violation. That stays a separate step, through
 * the existing violation workflow, and the review only keeps the link.
 */
class AttendanceReview extends Model
{
    public const LABELS = [
        'for_review' => 'For review',
        'under_verification' => 'Under verification',
        'verified' => 'Verified',
        'corrected' => 'Corrected',
        'closed' => 'Closed',
    ];

    /** Where each status may move next. */
    public const TRANSITIONS = [
        'for_review' => ['under_verification', 'verified', 'corrected', 'closed'],
        'under_verification' => ['verified', 'corrected', 'closed'],
        'verified' => ['under_verification', 'corrected', 'closed'],
        'corrected' => ['under_verification', 'closed'],
        // Reopened only with a reason.
        'closed' => ['under_verification'],
    ];

    /** A move into these needs a note saying what was found. */
    public const NOTE_REQUIRED = ['verified', 'corrected', 'closed'];

    protected $fillable = [
        'employee_id',
        'work_date',
        'client_company_id',
        'client_department_id',
        'status',
        'discrepancy_types',
        'snapshot',
        'latest_note',
        'employee_violation_id',
        'opened_by',
        'updated_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'discrepancy_types' => 'array',
            'snapshot' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function violation(): BelongsTo
    {
        return $this->belongsTo(EmployeeViolation::class, 'employee_violation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceReviewEvent::class)->orderBy('id');
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    /**
     * Where a compared day stands in the review queue, or null when it is not
     * in the queue at all.
     *
     * The one definition every attendance view reads - the employee's own tab,
     * the review queue, and the review itself - so they cannot disagree. A
     * flagged day nobody has acted on is "For review". Once reviewed it carries
     * the review's own status, even after a correction has cleared the flag,
     * because somebody still has to close it.
     */
    public static function statusFor(array $day, ?self $review): ?string
    {
        return $review?->status ?? (($day['status'] ?? null) === 'discrepancy' ? 'for_review' : null);
    }
}
