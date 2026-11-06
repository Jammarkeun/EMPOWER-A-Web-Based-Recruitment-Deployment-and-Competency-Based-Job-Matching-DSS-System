<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the pre-termination clearance.
 *
 * Created from config('empower.separation.termination_clearance') when an
 * administrator approves the request, and completed by HR before the
 * termination can be finalised (SeparationService).
 */
class TerminationClearanceItem extends Model
{
    public const STATUSES = ['pending_review', 'requires_action', 'awaiting_verification', 'cleared', 'not_applicable'];

    public const LABELS = [
        'pending_review' => 'Pending review',
        'requires_action' => 'Requires action',
        'awaiting_verification' => 'Awaiting verification',
        'cleared' => 'Cleared',
        'not_applicable' => 'Not applicable',
    ];

    /**
     * Items no longer part of the clearance. Rows copied onto requests before
     * an item was removed stay on file as they were, but are not shown,
     * counted, edited, or allowed to hold up finalisation.
     *
     * office_clearance: an office-by-office signed clearance, removed
     * 2026-11-03 - our assumption, not a step CDE described.
     */
    public const RETIRED = ['office_clearance'];

    /** The outcomes that let a termination be finalised. */
    public const RESOLVED = ['cleared', 'not_applicable'];

    /**
     * A record review has two states only - not yet read, and read. It is
     * never "not applicable" and never "requires action": the record is
     * reviewed, whatever it holds.
     */
    public const STATUSES_BY_KIND = [
        'record_review' => ['pending_review', 'cleared'],
        'obligation' => self::STATUSES,
        // Retired items only (RETIRED); kept so their rows still read correctly.
        'clearance' => self::STATUSES,
    ];

    protected $fillable = [
        'termination_id',
        'item_key',
        'label',
        'kind',
        'description',
        'is_mandatory',
        'requires_remarks',
        'status',
        'remarks',
        // `reference` is no longer asked for (2026-11-03). The column stays,
        // unused, so anything recorded in it before is kept.
        'verified_by',
        'verified_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'requires_remarks' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function termination(): BelongsTo
    {
        return $this->belongsTo(Termination::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isRetired(): bool
    {
        return in_array($this->item_key, self::RETIRED, true);
    }

    public function isResolved(): bool
    {
        return in_array($this->status, self::RESOLVED, true);
    }

    /** @return list<string> */
    public function allowedStatuses(): array
    {
        return self::STATUSES_BY_KIND[$this->kind] ?? self::STATUSES;
    }

    public function statusLabel(): string
    {
        if ($this->kind === 'record_review' && $this->status === 'cleared') {
            return 'Reviewed';
        }

        return self::LABELS[$this->status] ?? $this->status;
    }
}
