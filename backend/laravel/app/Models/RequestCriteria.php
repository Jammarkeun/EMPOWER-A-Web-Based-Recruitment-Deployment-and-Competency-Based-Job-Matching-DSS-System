<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestCriteria extends Model
{
    use HasFactory;

    protected $table = 'request_criteria';

    protected $fillable = [
        'job_request_id',
        'criteria_id',
        'mandatory_flag',
        'weight_score',
        'expected_value',
        'min_value',
        'max_value',
        'rubric_json',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mandatory_flag' => 'boolean',
            'weight_score' => 'decimal:2',
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'rubric_json' => 'array',
        ];
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(CriteriaCatalog::class, 'criteria_id');
    }
}
