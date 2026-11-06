<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An applicant's working copy of their digital Bio-Data.
 *
 * Saved as they go, and nothing more: a draft is never a submission, is never
 * shown to HR, and never changes the checklist or the profile. Submitting
 * copies it into an ApplicantBioDataVersion.
 */
class ApplicantBioData extends Model
{
    protected $table = 'applicant_bio_data';

    protected $fillable = ['applicant_id', 'draft', 'draft_saved_at'];

    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'draft_saved_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
