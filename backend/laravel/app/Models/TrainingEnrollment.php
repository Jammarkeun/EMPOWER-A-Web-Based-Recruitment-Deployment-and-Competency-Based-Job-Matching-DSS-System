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

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function hasCompleted(): bool
    {
        return $this->completion_status === 'completed';
    }
}
