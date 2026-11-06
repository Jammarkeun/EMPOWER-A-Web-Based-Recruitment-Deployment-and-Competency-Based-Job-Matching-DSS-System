<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a schedule submission's life: who moved it, from what to what,
 * and why. Written only by ScheduleSubmissionService, never edited.
 */
class ScheduleSubmissionEvent extends Model
{
    protected $fillable = [
        'schedule_submission_id',
        'from_status',
        'to_status',
        'note',
        'changes',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ScheduleSubmission::class, 'schedule_submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
