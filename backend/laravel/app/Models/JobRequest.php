<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
     * Everybody this request's evaluation looks at: applicants far enough
     * along to be considered, less anyone already deployed against it.
     *
     * Shared by the evaluation and by evaluationState(), so the number shown
     * as "not yet evaluated" is counted against the same pool the evaluation
     * ranks.
     */
    public function candidatePool(): Builder
    {
        return Applicant::query()
            ->evaluable()
            ->whereDoesntHave('employee.deployments', fn ($q) => $q->where('job_request_id', $this->id));
    }

    /**
     * Whether the ranking on screen can be relied on as it stands.
     *
     * Out of date (`is_stale`) when a ranked result was scored against
     * criteria or a profile that has since changed: each result records the
     * versions it was scored against, so this is exact even for a change made
     * while an evaluation was running (audit S-02).
     *
     * Provisional (`is_provisional`) when the ranking does not cover the pool
     * as it is now: eligible applicants nobody has scored yet, results carried
     * over from an earlier, narrower run, or ranked applicants who have since
     * left the pool (audit S-01). Ranks are still consistent - every current
     * result is ranked together - but they are not the whole picture.
     */
    public function evaluationState(): array
    {
        $evaluable = config('empower.evaluable_applicant_statuses');
        $placeholders = implode(', ', array_fill(0, count($evaluable), '?'));

        // One pass over the ranked results, so the request screen does not pay
        // a round trip per figure against a remote database.
        $stats = DB::table('job_request_matches as m')
            ->join('applicants as a', 'a.id', '=', 'm.applicant_id')
            ->where('m.job_request_id', $this->id)
            ->whereNotNull('m.rank_order')
            ->selectRaw('COUNT(*) AS ranked')
            ->selectRaw('MAX(m.evaluated_at) AS last_evaluated')
            ->selectRaw(
                'COUNT(CASE WHEN m.scored_criteria_version IS NULL OR m.scored_criteria_version <> ? THEN 1 END) AS criteria_stale',
                [(int) $this->criteria_version]
            )
            ->selectRaw('COUNT(CASE WHEN m.scored_profile_version IS NULL OR m.scored_profile_version <> a.profile_version THEN 1 END) AS profiles_stale')
            ->selectRaw(
                'COUNT(CASE WHEN m.evaluation_run IS NULL OR m.evaluation_run < ? THEN 1 END) AS from_earlier',
                [(int) $this->evaluation_runs]
            )
            ->selectRaw("COUNT(CASE WHEN a.deleted_at IS NULL AND a.current_status IN ({$placeholders}) THEN 1 END) AS in_pool", $evaluable)
            ->first();

        $ranked = (int) ($stats->ranked ?? 0);
        $inPool = (int) ($stats->in_pool ?? 0);
        $lastEvaluated = $stats?->last_evaluated ? Carbon::parse($stats->last_evaluated) : null;

        $criteriaSaved = $this->criteria()->max('updated_at');
        $criteriaSaved = $criteriaSaved ? Carbon::parse($criteriaSaved) : null;

        // Results carried over from an earlier run than the latest one: only
        // a narrowed run leaves any. Runs are numbered; a request last
        // evaluated before they were has nothing to compare against.
        $fromEarlierRuns = (int) $this->evaluation_runs > 0 ? (int) ($stats->from_earlier ?? 0) : 0;

        $poolSize = $this->candidatePool()->count();
        $notYetEvaluated = max(0, $poolSize - $inPool);
        $leftPool = max(0, $ranked - $inPool);

        $criteriaChanged = (int) ($stats->criteria_stale ?? 0) > 0;
        $profilesChanged = (int) ($stats->profiles_stale ?? 0);
        $isStale = $criteriaChanged || $profilesChanged > 0;

        $reasons = [];
        if ($ranked > 0) {
            if ($notYetEvaluated > 0) {
                $reasons[] = "{$notYetEvaluated} eligible applicant(s) have not been evaluated yet.";
            }
            if ($fromEarlierRuns > 0) {
                $reasons[] = "{$fromEarlierRuns} result(s) were carried over from an earlier, narrower evaluation.";
            }
            if ($leftPool > 0) {
                $reasons[] = "{$leftPool} ranked applicant(s) are no longer in the eligible pool.";
            }
            if ($criteriaChanged) {
                $reasons[] = 'The criteria have changed since some results were scored.';
            }
            if ($profilesChanged > 0) {
                $reasons[] = "{$profilesChanged} applicant profile(s) changed after they were scored.";
            }
        }

        return [
            'last_evaluated_at' => $lastEvaluated?->toIso8601String(),
            'criteria_saved_at' => $criteriaSaved?->toIso8601String(),
            'criteria_changed' => $criteriaChanged,
            'profiles_changed' => $profilesChanged,
            'is_stale' => $isStale,
            'pool_size' => $poolSize,
            'ranked' => $ranked,
            'not_yet_evaluated' => $notYetEvaluated,
            'from_earlier_runs' => $fromEarlierRuns,
            'left_pool' => $leftPool,
            'is_provisional' => $reasons !== [],
            'provisional_reasons' => $reasons,
        ];
    }

    /** Every status change, newest first (audit R-16). */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobRequestStatusHistory::class)->orderByDesc('changed_at')->orderByDesc('id');
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
