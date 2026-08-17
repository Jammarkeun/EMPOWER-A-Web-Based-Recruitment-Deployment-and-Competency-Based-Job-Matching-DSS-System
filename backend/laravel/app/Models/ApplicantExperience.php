<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicantExperience extends Model
{
    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'company_name',
        'position_title',
        'start_date',
        'end_date',
        'months_experience',
        'responsibilities',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'months_experience' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    /**
     * Derives the duration from the dates when HR did not type one in. An open
     * end date is treated as employment continuing to today, which is how a
     * currently-employed applicant's tenure should count.
     */
    public function resolveMonthsExperience(): int
    {
        if (! is_null($this->months_experience)) {
            return (int) $this->months_experience;
        }

        if (is_null($this->start_date)) {
            return 0;
        }

        return $this->start_date->diffInMonths($this->end_date ?? now());
    }
}
