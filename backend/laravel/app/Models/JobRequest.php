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
        'job_position_id',
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
            'date_requested' => 'date:Y-m-d',
            'deployment_deadline' => 'date:Y-m-d',
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

    /**
     * The role this request is for.
     *
     * position_title is kept alongside it as the wording used when the request
     * was raised, so renaming a position later does not rewrite history.
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'job_position_id');
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
    /**
     * Whether the saved ranking still reflects the saved criteria.
     *
     * Saving criteria replaces them, so the newest criterion row says when the
     * criteria last changed. If that is later than the last evaluation, the
     * ranking on screen was produced under rules that no longer apply and must
     * be run again before anybody relies on it.
     */
    public function evaluationState(): array
    {
        // reorder(): matches() sorts by rank, and PostgreSQL refuses an
        // aggregate alongside an ORDER BY on an ungrouped column.
        $lastEvaluated = $this->matches()->reorder()->max('evaluated_at');
        $criteriaSaved = $this->criteria()->max('updated_at');

        $lastEvaluated = $lastEvaluated ? \Illuminate\Support\Carbon::parse($lastEvaluated) : null;
        $criteriaSaved = $criteriaSaved ? \Illuminate\Support\Carbon::parse($criteriaSaved) : null;

        $criteriaChanged = $lastEvaluated !== null
            && $criteriaSaved !== null
            && $criteriaSaved->greaterThan($lastEvaluated);

        // Candidates whose scored profile was edited after they were ranked:
        // their score on screen no longer describes them.
        $profilesChanged = $this->matches()->reorder()
            ->whereHas('applicant', fn ($q) => $q->whereColumn('applicants.profile_updated_at', '>', 'job_request_matches.evaluated_at'))
            ->count();

        return [
            'last_evaluated_at' => $lastEvaluated?->toIso8601String(),
            'criteria_saved_at' => $criteriaSaved?->toIso8601String(),
            'criteria_changed' => $criteriaChanged,
            'profiles_changed' => $profilesChanged,
            'is_stale' => $criteriaChanged || $profilesChanged > 0,
        ];
    }

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
