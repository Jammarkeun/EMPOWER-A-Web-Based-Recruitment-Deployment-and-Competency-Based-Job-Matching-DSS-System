<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an employee is meant to work, from a given date.
 *
 * A change of schedule closes the previous row rather than editing it, so a
 * month's attendance is always compared with the schedule that applied in that
 * month.
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

    protected $fillable = [
        'employee_id',
        'effective_from',
        'effective_to',
        'work_days',
        'shift_start',
        'shift_end',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'work_days' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appliesOn(CarbonInterface $date): bool
    {
        return $date->greaterThanOrEqualTo($this->effective_from->startOfDay())
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
    public function time(string $column): string
    {
        return substr((string) $this->getAttribute($column), 0, 5);
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
