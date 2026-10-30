<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The applicant's answer to one endorsement: do they still want this job?
 *
 * Never edited. A correction is a new row that names the answer it corrects
 * and why, so the original stays readable (audit R-07). Written only by
 * PlacementService.
 */
class PlacementDecision extends Model
{
    public const DECISIONS = ['accepted', 'declined'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function endorsement(): BelongsTo
    {
        return $this->belongsTo(Endorsement::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_decision_id');
    }

    public function isDecline(): bool
    {
        return $this->decision === 'declined';
    }
}
