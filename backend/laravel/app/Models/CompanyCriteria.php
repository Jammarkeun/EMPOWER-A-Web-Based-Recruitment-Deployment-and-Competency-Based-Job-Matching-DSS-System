<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requirement a client company asks for as standard.
 *
 * These are defaults, copied onto a manpower request when it is raised. They are
 * never read by the scoring engine, which always works from the request's own
 * criteria - so editing a company default cannot retroactively change how an
 * earlier request was scored.
 */
class CompanyCriteria extends Model
{
    protected $table = 'company_criteria';

    protected $fillable = [
        'client_company_id',
        'criteria_id',
        'mandatory_flag',
        'weight_score',
        'expected_value',
        'min_value',
        'max_value',
        'rubric_json',
        'note',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function criterion(): BelongsTo
    {
        return $this->belongsTo(CriteriaCatalog::class, 'criteria_id');
    }
}
