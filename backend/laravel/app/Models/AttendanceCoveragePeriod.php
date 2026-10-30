<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Days for which an employee's DTR is known to have been received in full.
 *
 * Inside one, a scheduled day with nothing on file is a real gap in the DTR.
 * Outside every one, it only means the DTR has not been brought in yet, and
 * the comparison says so instead of guessing.
 */
class AttendanceCoveragePeriod extends Model
{
    protected $fillable = [
        'employee_id',
        'period_from',
        'period_to',
        'attendance_import_id',
        'note',
        'declared_by',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class, 'attendance_import_id');
    }

    public function covers(CarbonInterface $date): bool
    {
        return $date->greaterThanOrEqualTo($this->period_from->startOfDay())
            && $date->lessThanOrEqualTo($this->period_to->startOfDay());
    }
}
