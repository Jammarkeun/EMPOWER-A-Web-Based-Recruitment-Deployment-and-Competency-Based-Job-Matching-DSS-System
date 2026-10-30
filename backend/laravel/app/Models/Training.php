<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Training extends Model
{
    use HasFactory;

    public const STATUSES = ['scheduled', 'ongoing', 'completed', 'cancelled'];

    /**
     * Which session statuses may follow which.
     *
     * A completed session can be set back to scheduled or ongoing - that is the
     * deliberate way to add a late attendee. A cancelled one cannot come back:
     * cancelling released everybody scheduled only for it, so reopening it
     * would leave a roster of people no longer scheduled for anything. Schedule
     * a new session instead. (Our rule, not a stated CDE policy; the recording
     * does not discuss cancelled sessions.)
     */
    public const STATUS_TRANSITIONS = [
        'scheduled' => ['ongoing', 'completed', 'cancelled'],
        'ongoing' => ['scheduled', 'completed', 'cancelled'],
        'completed' => ['scheduled', 'ongoing'],
        'cancelled' => [],
    ];

    protected $fillable = [
        'training_code',
        'training_title',
        'training_date',
        'location',
        'trainer_name',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'training_date' => 'date:Y-m-d',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Whether applicants can still be added to the roster.
     *
     * A cancelled session never ran, and a completed one is over: adding
     * somebody to either would put them on a roster they were never part of.
     * Reopening a session (setting it back to scheduled or ongoing) is the
     * deliberate way to add a late attendee.
     */
    public function acceptsEnrolment(): bool
    {
        return in_array($this->status, ['scheduled', 'ongoing'], true);
    }

    /**
     * Whether attendance and completion can be recorded yet.
     *
     * Not before the session's date - an officer cannot mark somebody present
     * at training that has not happened - and never for a cancelled session.
     */
    public function canRecordOutcomes(): bool
    {
        return ! $this->isCancelled()
            && $this->training_date !== null
            && ! $this->training_date->isFuture();
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::STATUS_TRANSITIONS[$this->status] ?? [], true);
    }

    /** Whether anybody on the roster has attendance or a completion recorded. */
    public function hasRecordedOutcomes(): bool
    {
        return $this->enrollments()
            ->where(fn ($q) => $q->where('attendance_status', '!=', 'pending')
                ->orWhere('completion_status', 'completed'))
            ->exists();
    }
}
