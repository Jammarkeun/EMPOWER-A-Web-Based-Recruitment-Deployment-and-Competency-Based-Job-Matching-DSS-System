<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the folder recalculation (audit R-17). Written by
 * FolderRecalculationService only.
 */
class FolderRecalculation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** A run still marked running this long after it started was interrupted. */
    public const INTERRUPTED_AFTER_MINUTES = 15;

    /**
     * The state to show: a run whose process died part-way never gets to mark
     * itself failed, so one that has been "running" implausibly long is shown
     * as interrupted instead of as still in progress.
     */
    public function state(): string
    {
        if ($this->status === 'running'
            && $this->started_at?->lt(now()->subMinutes(self::INTERRUPTED_AFTER_MINUTES))) {
            return 'interrupted';
        }

        return $this->status;
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger,
            'reason' => $this->reason,
            'state' => $this->state(),
            'applicants_checked' => $this->applicants_checked,
            'applicants_changed' => $this->applicants_changed,
            'summary' => $this->summary ?? [],
            'error' => $this->error,
            'requested_by' => $this->requester?->full_name,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'needs_retry' => in_array($this->state(), ['failed', 'interrupted'], true),
        ];
    }

    public function requirementType(): BelongsTo
    {
        return $this->belongsTo(RequirementType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
