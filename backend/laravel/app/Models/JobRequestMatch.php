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
        ];
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
