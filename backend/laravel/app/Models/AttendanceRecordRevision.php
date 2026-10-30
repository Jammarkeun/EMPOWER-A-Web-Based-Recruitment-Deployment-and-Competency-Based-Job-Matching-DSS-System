<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a day of the DTR: what it was, what it became, who changed it
 * and why.
 */
class AttendanceRecordRevision extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'attendance_record_id',
        'action',
        'old_time_in',
        'old_time_out',
        'old_remarks',
        'old_source',
        'new_time_in',
        'new_time_out',
        'new_remarks',
        'new_source',
        'reason',
        'attendance_import_id',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'old_time_in' => 'datetime',
            'old_time_out' => 'datetime',
            'new_time_in' => 'datetime',
            'new_time_out' => 'datetime',
        ];
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class, 'attendance_import_id');
    }
}
