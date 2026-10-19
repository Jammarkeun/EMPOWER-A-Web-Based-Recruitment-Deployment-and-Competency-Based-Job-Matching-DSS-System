<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRequestMatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_request_id',
        'applicant_id',
        'raw_score',
        'max_score',
        'percentage_score',
        'rank_order',
        'recommendation_level',
        'hard_filter_pass',
        'breakdown_json',
        'is_shortlisted',
        'shortlisted_at',
        'shortlisted_by',
        'evaluated_by',
        'evaluated_at',
        'scored_profile_version',
        'scored_criteria_version',
        'evaluation_run',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'percentage_score' => 'decimal:2',
            'rank_order' => 'integer',
            'hard_filter_pass' => 'boolean',
            'is_shortlisted' => 'boolean',
            'breakdown_json' => 'array',
            'shortlisted_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'scored_profile_version' => 'integer',
            'scored_criteria_version' => 'integer',
            'evaluation_run' => 'integer',
        ];
    }

    /**
     * Whether this ranked result was carried over from an earlier, narrower
     * run rather than scored by the latest one (audit S-01). Needs the request
     * loaded; results from before runs were numbered never count.
     */
    public function isFromEarlierRun(): bool
    {
        if ($this->rank_order === null || ! $this->relationLoaded('jobRequest') || ! $this->jobRequest) {
            return false;
        }

        $latest = (int) $this->jobRequest->evaluation_runs;

        return $latest > 0 && ($this->evaluation_run === null || $this->evaluation_run < $latest);
    }

    /**
     * Why this result no longer describes the applicant as they are now
     * (audit S-01, S-02). Empty when it still does.
     *
     * Only judged for a ranked result, and only from what is loaded: the
     * request for its criteria version, the applicant for their profile
     * version and status. A shortlisted result kept as history has no rank
     * and is not a recommendation, so it is never called out of date.
     *
     * @return list<string>
     */
    public function staleReasons(): array
    {
        if ($this->rank_order === null) {
            return [];
        }

        $reasons = [];

        if ($this->relationLoaded('jobRequest') && $this->jobRequest
            && $this->scored_criteria_version !== (int) $this->jobRequest->criteria_version) {
            $reasons[] = 'criteria_changed';
        }

        if ($this->relationLoaded('applicant') && $this->applicant) {
            if ($this->scored_profile_version !== (int) $this->applicant->profile_version) {
                $reasons[] = 'profile_changed';
            }

            if (! in_array($this->applicant->current_status, config('empower.evaluable_applicant_statuses'), true)) {
                $reasons[] = 'left_pool';
            }
        }

        return $reasons;
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    public function scopeQualified(Builder $query): Builder
    {
        return $query->where('hard_filter_pass', true);
    }

    public function scopeShortlisted(Builder $query): Builder
    {
        return $query->where('is_shortlisted', true);
    }
}
