<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an attendance review: the move, the note, and who made it.
 */
class AttendanceReviewEvent extends Model
{
    protected $fillable = [
        'attendance_review_id',
        'from_status',
        'to_status',
        'note',
        'snapshot',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
