<?php

namespace App\Models;

use App\Support\DocumentCompliance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class ApplicantRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'applicant_id',
        'requirement_type_id',
        'status',
        'file_path',
        'file_name',
        'file_mime',
        'file_size_bytes',
        'file_hash',
        'submitted_at',
        'first_viewed_at',
        'first_viewed_by',
        'verified_at',
        'verified_by',
        'verification_method',
        'verification_note',
        'expiry_date',
        'rejection_reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'verified_at' => 'datetime',
            'expiry_date' => 'date:Y-m-d',
            'file_size_bytes' => 'integer',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function requirementType(): BelongsTo
    {
        return $this->belongsTo(RequirementType::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function firstViewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_viewed_by');
    }

    /**
     * Records that a member of staff has opened this document.
     *
     * Only the first opening is kept. The question being answered is "has this
     * entered review?", and a timestamp that moved every time somebody reopened
     * the file would answer a different and much less useful one.
     *
     * Saved without touching updated_at: opening a document to read it is not a
     * change to the applicant's record, and letting it bump the timestamp would
     * make every "recently updated" list meaningless.
     */
    public function markFirstViewedBy(User $staff): bool
    {
        if ($this->first_viewed_at) {
            return false;
        }

        $this->forceFill([
            'first_viewed_at' => now(),
            'first_viewed_by' => $staff->id,
        ]);

        $this->timestamps = false;
        $this->saveQuietly();
        $this->timestamps = true;

        return true;
    }

    /**
     * Where this document stands, as an applicant would understand it.
     *
     * Deliberately derived rather than stored. `status` is the officer's
     * decision and `first_viewed_at` is a record of what has happened to the
     * file; this combines them into the one sentence worth showing somebody
     * waiting to hear about their application.
     *
     * The distinction that matters is between the middle two. A document that
     * has been uploaded and one that is being checked look identical in the
     * database's `status` column - both are "submitted" - but they are not the
     * same thing to the person who sent it, and telling them their document was
     * under review when nobody had opened it was simply untrue.
     */
    public function reviewState(): string
    {
        if ($this->isExpired() && $this->status === 'verified') {
            return 'expired';
        }

        return match ($this->status) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            'needs_correction' => 'needs_correction',
            'expired' => 'expired',
            // Set by hand when an officer parks a document mid-review, so it is
            // under review by definition regardless of the view record.
            'pending' => 'under_review',
            'submitted' => $this->first_viewed_at ? 'under_review' : 'uploaded',
            default => $this->file_path ? 'uploaded' : 'not_uploaded',
        };
    }

    /**
     * Only a verified, unexpired document counts toward folder categorisation.
     *
     * Expiry matters in practice: medical certificates and police clearances go
     * stale, and a candidate whose clearance lapsed while waiting for a posting
     * is not actually deployable.
     *
     * Once the person is deployed, a lapse counts only where the document must
     * be renewed while employed (CDE, 26 October 2026); see DocumentCompliance.
     * Called with no arguments it judges as before deployment, which is how it
     * always behaved.
     */
    public function countsAsComplete(?string $postDeploymentRule = null, string $stage = DocumentCompliance::STAGE_PRE_DEPLOYMENT): bool
    {
        return DocumentCompliance::countsAsComplete($this, $postDeploymentRule, $stage);
    }

    /** Earlier versions of this document that renewals replaced, newest first. */
    public function history(): HasMany
    {
        return $this->hasMany(ApplicantRequirementHistory::class)->orderByDesc('replaced_at')->orderByDesc('id');
    }

    /**
     * Keeps the version a renewal replaces.
     *
     * Whenever a document that had been verified gets a different file or a
     * different expiry date - a portal renewal, or HR verifying a renewed
     * original at the counter - the version on file is copied into the
     * history first. The clearance a worker was deployed on, and every annual
     * medical before the current one, stay on their record.
     */
    protected static function booted(): void
    {
        static::updating(function (ApplicantRequirement $requirement) {
            $wasVerified = in_array($requirement->getOriginal('status'), ['verified', 'expired'], true)
                && $requirement->getOriginal('verified_at') !== null;

            if (! $wasVerified || ! $requirement->isDirty(['file_path', 'expiry_date'])) {
                return;
            }

            ApplicantRequirementHistory::create([
                'applicant_requirement_id' => $requirement->id,
                'applicant_id' => $requirement->getOriginal('applicant_id'),
                'requirement_type_id' => $requirement->getOriginal('requirement_type_id'),
                'status' => $requirement->getOriginal('status'),
                'file_path' => $requirement->getOriginal('file_path'),
                'file_name' => $requirement->getOriginal('file_name'),
                'expiry_date' => $requirement->getRawOriginal('expiry_date'),
                'verified_at' => $requirement->getOriginal('verified_at'),
                'verified_by' => $requirement->getOriginal('verified_by'),
                'verification_method' => $requirement->getOriginal('verification_method'),
                'reason' => $requirement->isDirty('file_path') ? 'renewal' : 'expiry_corrected',
                'replaced_at' => now(),
                'replaced_by' => Auth::id(),
            ]);
        });
    }

    public function isExpired(): bool
    {
        return ! is_null($this->expiry_date) && $this->expiry_date->isPast();
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', 'verified');
    }
}
