<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantEducation extends Model
{
    use HasFactory;

    /**
     * Set explicitly because "education" is a mass noun: Laravel's inflector
     * leaves it unchanged and would look for an "applicant_education" table.
     */
    protected $table = 'applicant_educations';

    protected $fillable = [
        'applicant_id',
        'education_level',
        'school_name',
        'course_program',
        'graduation_year',
        'honors',
    ];

    protected function casts(): array
    {
        return [
            'graduation_year' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
