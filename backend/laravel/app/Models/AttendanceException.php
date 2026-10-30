<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day, or run of days, the agency already knows is not a normal work day:
 * approved leave, a holiday, a day the client's site did not operate.
 *
 * Recorded for one employee, or for everybody placed at a client (optionally
 * one department). Withdrawn rather than deleted.
 */
class AttendanceException extends Model
{
    public const TYPES = [
        'approved_leave' => 'Approved leave',
        'holiday' => 'Holiday',
        'client_non_working_day' => 'Client non-working day',
        'other' => 'Other exception',
    ];

    protected $fillable = [
        'employee_id',
        'client_company_id',
        'client_department_id',
        'exception_type',
        'date_from',
        'date_to',
        'remarks',
        'recorded_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'voided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function label(): string
    {
        return self::TYPES[$this->exception_type] ?? $this->exception_type;
    }

    /**
     * Whether this exception covers the given day at the given placement.
     */
    public function appliesTo(CarbonInterface $date, ?int $companyId, ?int $departmentId): bool
    {
        if ($this->voided_at !== null
            || $date->lessThan($this->date_from->startOfDay())
            || $date->greaterThan($this->date_to->startOfDay())) {
            return false;
        }

        if ($this->employee_id !== null) {
            return true;
        }

        return $this->client_company_id === $companyId
            && ($this->client_department_id === null || $this->client_department_id === $departmentId);
    }
}
