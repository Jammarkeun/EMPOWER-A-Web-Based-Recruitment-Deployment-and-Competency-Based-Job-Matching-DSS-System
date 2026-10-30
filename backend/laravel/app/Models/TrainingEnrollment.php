<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'training_id',
        'applicant_id',
        'attendance_status',
        'completion_status',
        'remarks',
    ];

    /*
     * Who marked attendance and who recorded completion are set by the training
     * workflow itself, never from request input, so they are not fillable.
     */
    protected function casts(): array
    {
        return [
            'attendance_recorded_at' => 'datetime',
            'completion_recorded_at' => 'datetime',
        ];
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function attendanceRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_recorded_by');
    }

    public function completionRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completion_recorded_by');
    }

    public function hasCompleted(): bool
    {
        return $this->completion_status === 'completed';
    }

    /** Nothing has been recorded against this enrolment yet. */
    public function isUntouched(): bool
    {
        return $this->attendance_status === 'pending' && $this->completion_status !== 'completed';
    }
}
