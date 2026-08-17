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
            'hire_date' => 'date',
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

    public function resignation(): HasOne
    {
        return $this->hasOne(Resignation::class)->latestOfMany();
    }

    public function termination(): HasOne
    {
        return $this->hasOne(Termination::class)->latestOfMany();
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
}
