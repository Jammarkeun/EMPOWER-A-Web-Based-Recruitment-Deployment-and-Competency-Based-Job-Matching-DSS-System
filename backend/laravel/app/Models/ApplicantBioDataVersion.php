<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submitted Bio-Data, exactly as the applicant sent it.
 *
 * Never edited after it is created apart from HR's decision about it. A
 * correction is a new version, so what was verified, and what was sent back
 * and why, can always be read again.
 */
class ApplicantBioDataVersion extends Model
{
    public const VERIFIED = 'verified';

    public const NEEDS_CORRECTION = 'needs_correction';

    protected $fillable = [
        'applicant_id',
        'version_number',
        'data',
        'submitted_at',
        'submitted_by',
        'review_outcome',
        'reviewed_at',
        'reviewed_by',
        'review_note',
        'applied_changes',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'data' => 'array',
            'applied_changes' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Sent, and HR has not decided yet. */
    public function isPending(): bool
    {
        return $this->review_outcome === null;
    }
}
