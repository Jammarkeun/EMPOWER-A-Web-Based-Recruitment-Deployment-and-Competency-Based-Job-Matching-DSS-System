<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Termination extends Model
{
    use HasFactory;

    /**
     * The request and its outcome, on one row (SeparationService):
     *
     *   draft -> for_review                 HR submits it
     *   for_review -> approved              an administrator approves it
     *              -> returned              ... or returns it for correction
     *              -> rejected              ... or rejects it (closed)
     *   returned -> for_review              HR corrects and resubmits it
     *   approved -> finalized               HR, once the clearance is complete
     *   any open status -> withdrawn        closed without dismissing anybody
     *
     * Approval changes nothing about the employee. Only finalisation does.
     * "Clearance in progress" and "ready for finalisation" are stages of an
     * approved request, read from its checklist rather than stored.
     */
    public const STATUSES = ['draft', 'for_review', 'returned', 'approved', 'rejected', 'finalized', 'withdrawn'];

    public const LABELS = [
        'draft' => 'Draft',
        'for_review' => 'Submitted for administrator review',
        'returned' => 'Returned for correction',
        'approved' => 'Approved - pending final processing',
        'rejected' => 'Rejected',
        'finalized' => 'Terminated - finalised',
        'withdrawn' => 'Withdrawn',
    ];

    /** HR may edit the request while it is with HR, before any decision. */
    public const EDITABLE = ['draft', 'returned'];

    protected $fillable = [
        'employee_id',
        'reason',
        'termination_date',
        'documents_path',
        'remarks',
        'status',
        'deployment_id',
        'approved_by',
        'approved_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'created_by',
        'submitted_at',
        'finalized_at',
        'finalized_by',
        'withdrawn_by',
        'withdrawn_at',
        'withdrawal_reason',
        'employee_message',
        'clearance_status',
        'notice_notification_id',
        'notice_viewed_at',
        'notice_acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'termination_date' => 'date:Y-m-d',
            'finalized_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'notice_viewed_at' => 'datetime',
            'notice_acknowledged_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keeps the database's one-open-case guard in step with the status,
        // whichever path changed it (see the 2026_11_03_000300 migration).
        static::saving(function (Termination $termination) {
            $termination->open_employee_id = $termination->isOpen() ? $termination->employee_id : null;
        });
    }

    /** The request's reference, as HR and the administrator quote it. */
    public function getReferenceAttribute(): string
    {
        return sprintf('TRM-%05d', $this->id);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SeparationEvent::class, 'case_id')
            ->where('case_type', 'termination')
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The administrator who approved the request. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Whoever made the latest review decision: approve, reject, or return. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** The HR officer who finalised it. */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * The clearance as it applies now. Everything that shows, counts, or
     * checks the clearance reads this, so a retired item stops blocking an
     * open request the moment it is retired - its row stays on file.
     */
    public function clearanceItems(): HasMany
    {
        return $this->hasMany(TerminationClearanceItem::class)
            ->whereNotIn('item_key', TerminationClearanceItem::RETIRED)
            ->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function withdrawer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /**
     * Statuses in which a termination is still awaiting its outcome.
     *
     * A case ends finalised, rejected by the administrator, or withdrawn.
     * Whichever it is, it is closed and stays on file exactly as it stood.
     */
    public const OPEN_STATUSES = ['draft', 'for_review', 'returned', 'approved'];

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    /**
     * Finalised under the one-step process, before requests were reviewed and
     * cleared first: no approval date and no checklist of its own.
     */
    public function isLegacy(): bool
    {
        return $this->isFinalized() && $this->approved_at === null;
    }

    /**
     * The mandatory checklist items still standing between an approved
     * request and its finalisation. Empty means ready.
     *
     * @return Collection<int, TerminationClearanceItem>
     */
    public function outstandingClearance(): Collection
    {
        return $this->clearanceItems
            ->filter(fn (TerminationClearanceItem $item) => $item->is_mandatory && ! $item->isResolved())
            ->values();
    }

    /**
     * Where the case stands, in finer steps than the status: an approved
     * request is either still being cleared or ready to finalise.
     */
    public function stage(): string
    {
        if ($this->status !== 'approved') {
            return $this->status;
        }

        return $this->outstandingClearance()->isEmpty() ? 'ready_for_finalization' : 'clearance_in_progress';
    }

    public const STAGE_LABELS = [
        'clearance_in_progress' => 'Approved - clearance in progress',
        'ready_for_finalization' => 'Approved - ready for finalisation',
    ];

    public function stageLabel(): string
    {
        $stage = $this->stage();

        return self::STAGE_LABELS[$stage] ?? self::LABELS[$stage] ?? $stage;
    }
}
