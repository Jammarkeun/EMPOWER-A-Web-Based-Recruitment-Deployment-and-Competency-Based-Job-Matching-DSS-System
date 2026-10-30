<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of an employee's DTR, as the client's timekeeping recorded it.
 *
 * Typed in by HR from the client's sheet, or imported from a file the client
 * sent. Every change is written to a revision first, so the times a
 * comparison was once made from are never lost.
 */
class AttendanceRecord extends Model
{
    public const SOURCES = ['manual', 'import'];

    protected $fillable = [
        'employee_id',
        'work_date',
        'time_in',
        'time_out',
        'remarks',
        'source',
        'attendance_import_id',
        'source_row',
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

    public function import(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class, 'attendance_import_id');
    }
}
