<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeViolation extends Model
{
    use HasFactory;

    /** The kinds of offence the agency records. */
    public const TYPES = ['awol', 'absences', 'suspension', 'late', 'misconduct', 'policy_violation'];

    protected $fillable = [
        'employee_id',
        'violation_report_id',
        'client_company_id',
        'client_department_id',
        'reported_by',
        'violation_date',
        'violation_type',
        'description',
        'evidence_path',
        'issued_by',
        'remarks',
        'penalty',
        'status',
        'resolution_date',
    ];

    protected function casts(): array
    {
        return [
            'violation_date' => 'date:Y-m-d',
            'resolution_date' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** The coordinator who reported it, when it came in through a report. */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ViolationReport::class, 'violation_report_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'under_review', 'escalated']);
    }

    /*
     * -------------------------------------------------------------------
     * The one-year record
     * -------------------------------------------------------------------
     *
     * CDE's handbook says a worker's record "goes back to zero" after a year.
     * That is a statement about what still counts against them, not a licence
     * to destroy the paperwork: an offence from two years ago remains on file,
     * remains in the disciplinary report, and remains part of the audit trail —
     * it simply stops counting towards the threshold.
     *
     * Keeping those two ideas apart is the whole point. Deleting the row would
     * lose the agency its own history and make a pattern of behaviour
     * impossible to show; leaving it counting forever would mean nobody's
     * record ever cleared, which is not what the agency told us it does.
     *
     * The window is read from configuration rather than fixed at twelve months,
     * because it is the client's policy and not the system's.
     */

    /** The date after which this offence stops counting towards the threshold. */
    public function expiresOn(): ?\Illuminate\Support\Carbon
    {
        return $this->violation_date?->copy()->addMonths(self::windowMonths());
    }

    public function isExpired(): bool
    {
        $expiry = $this->expiresOn();

        return $expiry !== null && $expiry->isPast();
    }

    /** Still counting: within the window, whatever its resolution status. */
    public function isActive(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * Offences still inside the active window.
     *
     * Written as a date comparison rather than a computed column so it stays
     * true as time passes without anything having to be recalculated — a record
     * ages out on its anniversary whether or not the system was running that
     * day.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereDate('violation_date', '>', now()->subMonths(self::windowMonths())->toDateString());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereDate('violation_date', '<=', now()->subMonths(self::windowMonths())->toDateString());
    }

    public static function windowMonths(): int
    {
        return (int) config('empower.violations.active_window_months', 12);
    }
}
