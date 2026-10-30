<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    use HasFactory;

    /**
     * Active while the worker is placed; ended when a separation ends the
     * placement. The deployments migration also lists "completed" and
     * "transferred", but nothing has ever written either - a move within the
     * client is a reassignment on the same deployment, a move to another
     * client a new one - so they are not accepted as filters (2026-10-30).
     */
    public const STATUSES = ['active', 'ended'];

    /**
     * What the placement history needs to be read in names rather than ids.
     * Prefix with the path to the deployment when loading it from elsewhere
     * (an employee's deployments, say).
     */
    public const HISTORY_RELATIONS = [
        'history.fromDepartment',
        'history.toDepartment',
        'history.fromCompany',
        'history.toCompany',
        'history.changedBy',
    ];

    protected $fillable = [
        'deployment_code',
        'employee_id',
        'job_request_id',
        'client_company_id',
        'client_department_id',
        'position_title',
        'supervisor_name',
        'deployment_date',
        'end_date',
        'deployment_status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deployment_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
        ];
    }

    /**
     * Keeps the database's one-active-deployment guard in step with the
     * status (audit S-04): the employee's id while the deployment is active,
     * empty once it is not. A missing status counts as active, as the column
     * default does. Bulk updates that bypass the model must set it themselves;
     * see SeparationService.
     */
    protected static function booted(): void
    {
        static::saving(function (Deployment $deployment) {
            $deployment->active_employee_id = ($deployment->deployment_status ?? 'active') === 'active'
                ? $deployment->employee_id
                : null;
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(DeploymentHistory::class)->orderByDesc('effective_date');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deployment_status', 'active');
    }
}
