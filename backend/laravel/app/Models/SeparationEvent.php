<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a resignation or termination case.
 *
 * HR reads every entry. The employee reads only those marked visible, and of
 * those only the event, the date, and `employee_note` - never `details`, which
 * holds internal values such as the fields an officer changed.
 */
class SeparationEvent extends Model
{
    protected $fillable = [
        'employee_id',
        'case_type',
        'case_id',
        'event',
        'from_status',
        'to_status',
        'employee_note',
        'details',
        'visible_to_employee',
        'actor_id',
        'actor_role',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'visible_to_employee' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
