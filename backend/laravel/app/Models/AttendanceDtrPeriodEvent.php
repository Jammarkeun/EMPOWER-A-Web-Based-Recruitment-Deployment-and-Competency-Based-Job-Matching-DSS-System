<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move in a DTR period's status: who, from what to what, and why. Written
 * only by DtrPeriodService, never edited.
 */
class AttendanceDtrPeriodEvent extends Model
{
    protected $fillable = [
        'attendance_dtr_period_id',
        'from_status',
        'to_status',
        'note',
        'details',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AttendanceDtrPeriod::class, 'attendance_dtr_period_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
