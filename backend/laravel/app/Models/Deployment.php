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
            'deployment_date' => 'date',
            'end_date' => 'date',
        ];
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
