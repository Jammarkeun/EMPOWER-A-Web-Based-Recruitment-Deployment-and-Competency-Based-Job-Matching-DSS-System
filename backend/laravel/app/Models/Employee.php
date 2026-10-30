<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Employee extends Model
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'applicant_id',
        'employee_number',
        'biometric_number',
        'current_client_company_id',
        'current_department_id',
        'current_position_title',
        'current_supervisor_name',
        'hire_date',
        'profile_notes',
        'created_by',
        'updated_by',
    ];

    /**
     * employment_status is excluded from mass assignment; it moves only through
     * EmployeeLifecycleService so that every change is validated and historised.
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['full_name'];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'current_client_company_id');
    }

    public function currentDepartment(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'current_department_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->orderByDesc('deployment_date');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(EmployeeViolation::class)->orderByDesc('violation_date');
    }

    /**
     * Offences still counting against this employee.
     *
     * The agency's record clears after a year, so this is the number that
     * matters operationally — the full history stays on `violations()` for
     * reporting and for showing a pattern of behaviour.
     */
    public function activeViolations(): HasMany
    {
        return $this->violations()->active();
    }

    /**
     * Whether this employee has reached the client's review threshold.
     *
     * Deliberately named for what it does. It does not terminate anybody and
     * must never be read as though it did: reaching the threshold raises the
     * question, and an administrator answers it. CDE's current policy is the
     * fourth offence, which is configuration rather than code because it is
     * their handbook and not our rule.
     */
    public function reachedViolationThreshold(): bool
    {
        $threshold = (int) config('empower.violations.termination_threshold', 4);

        if ($threshold < 1) {
            return false;
        }

        // reorder(): violations() sorts by date, and PostgreSQL refuses a
        // COUNT alongside an ORDER BY on an ungrouped column.
        return $this->activeViolations()->reorder()->count() >= $threshold;
    }

    public function resignation(): HasOne
    {
        return $this->hasOne(Resignation::class)->latestOfMany();
    }

    public function termination(): HasOne
    {
        return $this->hasOne(Termination::class)->latestOfMany();
    }

    /**
     * Every resignation ever filed, withdrawn ones included. The singular
     * relation above is the latest, which is what the screens show.
     */
    public function resignations(): HasMany
    {
        return $this->hasMany(Resignation::class);
    }

    public function terminations(): HasMany
    {
        return $this->hasMany(Termination::class);
    }

    /**
     * Every schedule this employee has worked to, newest first.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class)->orderByDesc('effective_from');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * The last day this person was employed, or null while they still are.
     *
     * Read from the placement that ended when they separated, which the
     * separation dates by the exit or termination date rather than by the day
     * it was processed.
     */
    public function employmentEndDate(): ?\Carbon\CarbonInterface
    {
        if ($this->isActive()) {
            return null;
        }

        // reorder(): the relation sorts by deployment date, and PostgreSQL
        // refuses an aggregate alongside an ORDER BY on an ungrouped column.
        $ended = $this->deployments()->reorder()->max('end_date');

        return $ended ? \Illuminate\Support\Carbon::parse($ended) : null;
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class)->orderByDesc('changed_at');
    }

    /**
     * Employees have no name columns of their own. The identity lives on the
     * applicant record, which is the point of the one-to-one link: a person is
     * the same person before and after deployment.
     */
    public function getFullNameAttribute(): ?string
    {
        return $this->applicant?->full_name;
    }

    public function isActive(): bool
    {
        return $this->employment_status === 'active';
    }

    public function activeDeployment(): ?Deployment
    {
        return $this->deployments()->where('deployment_status', 'active')->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('employment_status', 'active');
    }

    /**
     * Employed and placed today: an active employee with an active deployment.
     *
     * Deployment sets both and separation ends both, so they move together;
     * asking for both keeps a half-ended record out of a "deployed" list.
     */
    public function scopeCurrentlyDeployed(Builder $query): Builder
    {
        return $query->active()->whereHas('deployments', fn (Builder $deployment) => $deployment->active());
    }
}
