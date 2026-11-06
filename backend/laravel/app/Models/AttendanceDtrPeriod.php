<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One client's DTR for a run of days, and whether it has arrived in full.
 *
 * Files received for those days are filed under it. Received and Processing
 * follow from the files themselves; Incomplete Submission and Complete for
 * Period are HR's to say, with a note, and only Complete for Period turns a
 * scheduled day with nothing on file into a gap to verify. See
 * DtrPeriodService.
 */
class AttendanceDtrPeriod extends Model
{
    public const LABELS = [
        'received' => 'Received',
        'processing' => 'Processing',
        'incomplete' => 'Incomplete Submission',
        'complete' => 'Complete for Period',
    ];

    /** Set by HR, not by what happens to the files. */
    public const DECIDED = ['incomplete', 'complete'];

    protected $fillable = [
        'client_company_id',
        'period_from',
        'period_to',
        'status',
        'status_note',
        'status_by',
        'status_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'status_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(AttendanceImport::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AttendanceDtrPeriodEvent::class)->orderBy('id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_by');
    }

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }

    public function covers(CarbonInterface $date): bool
    {
        return $date->greaterThanOrEqualTo($this->period_from->startOfDay())
            && $date->lessThanOrEqualTo($this->period_to->startOfDay());
    }

    public function contains(CarbonInterface $from, CarbonInterface $to): bool
    {
        return $this->covers($from) && $this->covers($to);
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
