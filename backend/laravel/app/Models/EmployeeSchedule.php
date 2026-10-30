<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an employee is meant to work, from a given date, at a given placement.
 *
 * A change of schedule closes the previous row rather than editing it, so a
 * month's attendance is always compared with the schedule that applied in that
 * month. A schedule entered in error is voided - kept, but no longer compared
 * against - and a typo in one is corrected in place with the old values kept
 * in the audit trail.
 */
class EmployeeSchedule extends Model
{
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public const STATUSES = ['active', 'voided'];

    protected $fillable = [
        'employee_id',
        'deployment_id',
        'client_company_id',
        'client_department_id',
        'effective_from',
        'effective_to',
        'work_days',
        'shift_start',
        'shift_end',
        'break_start',
        'break_minutes',
        'remarks',
        'status',
        'created_by',
        'updated_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'work_days' => 'array',
            'break_minutes' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeInForce(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isVoided(): bool
    {
        return $this->status === 'voided';
    }

    public function appliesOn(CarbonInterface $date): bool
    {
        return ! $this->isVoided()
            && $date->greaterThanOrEqualTo($this->effective_from->startOfDay())
            && ($this->effective_to === null || $date->lessThanOrEqualTo($this->effective_to->startOfDay()));
    }

    public function isWorkDay(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), array_map('intval', $this->work_days ?? []), true);
    }

    /**
     * Whether the shift runs past midnight into the next day.
     */
    public function crossesMidnight(): bool
    {
        return $this->time('shift_end') <= $this->time('shift_start');
    }

    /**
     * "HH:MM", however the database returned the time column.
     */
    public function time(string $column): ?string
    {
        $value = $this->getAttribute($column);

        return $value === null ? null : substr((string) $value, 0, 5);
    }

    /**
     * A readable line such as "Mon-Fri, 08:00-17:00".
     */
    public function describe(): string
    {
        $days = array_map(
            fn ($d) => substr(self::WEEKDAYS[(int) $d] ?? '?', 0, 3),
            $this->work_days ?? []
        );

        return sprintf(
            '%s, %s-%s%s',
            implode(' ', $days),
            $this->time('shift_start'),
            $this->time('shift_end'),
            $this->crossesMidnight() ? ' (next day)' : ''
        );
    }
}
