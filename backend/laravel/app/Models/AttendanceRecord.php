<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of an employee's DTR, as the client's timekeeping recorded it.
 */
class AttendanceRecord extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'time_in',
        'time_out',
        'remarks',
        'recorded_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
