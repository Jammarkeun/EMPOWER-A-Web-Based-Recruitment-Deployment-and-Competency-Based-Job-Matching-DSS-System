<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A version of a document that a renewal replaced. Written only by
 * ApplicantRequirement when a verified document's file or expiry changes,
 * never edited.
 */
class ApplicantRequirementHistory extends Model
{
    protected $table = 'applicant_requirement_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date:Y-m-d',
            'verified_at' => 'datetime',
            'replaced_at' => 'datetime',
        ];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ApplicantRequirement::class, 'applicant_requirement_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function replacer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replaced_by');
    }
}
