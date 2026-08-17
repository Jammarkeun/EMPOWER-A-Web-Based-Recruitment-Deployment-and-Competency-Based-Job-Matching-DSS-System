<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantSkill extends Model
{
    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'skill_name',
        'proficiency_level',
        'years_experience',
    ];

    protected function casts(): array
    {
        return [
            'years_experience' => 'decimal:2',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
