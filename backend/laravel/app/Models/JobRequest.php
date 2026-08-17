<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'request_code',
        'client_company_id',
        'client_department_id',
        'position_title',
        'required_education',
        'required_experience_months',
        'required_certifications',
        'gender_preference',
        'age_min',
        'age_max',
        'height_min_cm',
        'physical_requirement',
        'availability_requirement',
        'workers_needed',
        'date_requested',
        'deployment_deadline',
        'request_source',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_requested' => 'date',
            'deployment_deadline' => 'date',
            'approved_at' => 'datetime',
            'height_min_cm' => 'decimal:2',
        ];
    }

    protected $appends = ['remaining_headcount'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'client_department_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(RequestCriteria::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(JobRequestMatch::class)->orderBy('rank_order');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getRemainingHeadcountAttribute(): int
    {
        return max(0, (int) $this->workers_needed - (int) $this->workers_fulfilled);
    }

    /**
     * Whether this request can still absorb a deployment.
     *
     * Guards against over-deploying against a closed or already-filled request,
     * which under the spreadsheet process was only caught when the client
     * complained about extra workers arriving.
     */
    public function canAcceptDeployment(): bool
    {
        return in_array($this->request_status, ['open', 'in_progress', 'partially_fulfilled'], true)
            && $this->remaining_headcount > 0;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('request_status', ['open', 'in_progress', 'partially_fulfilled']);
    }
}
