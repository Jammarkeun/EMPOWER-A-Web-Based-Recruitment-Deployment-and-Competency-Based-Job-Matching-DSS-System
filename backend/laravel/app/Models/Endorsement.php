<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An applicant put forward to a client company for evaluation.
 *
 * Opened when the applicant moves into client evaluation, closed when they are
 * deployed, sent back to the pool, or archived. The applicant's answer to the
 * placement belongs to the endorsement, not to the applicant, so declining one
 * client says nothing about the next (audit R-05, R-07).
 *
 * Every column is written by PlacementService; nothing is mass-assigned from a
 * request.
 */
class Endorsement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'endorsed_at' => 'datetime',
            'ended_at' => 'datetime',
            'recorded_retroactively' => 'boolean',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    public function endorser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'endorsed_by');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /** Every answer and correction, oldest first. */
    public function decisions(): HasMany
    {
        return $this->hasMany(PlacementDecision::class)->orderBy('id');
    }

    /** The answer in force: the latest recorded, corrections included. */
    public function currentDecision(): HasOne
    {
        return $this->hasOne(PlacementDecision::class)->latestOfMany();
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
