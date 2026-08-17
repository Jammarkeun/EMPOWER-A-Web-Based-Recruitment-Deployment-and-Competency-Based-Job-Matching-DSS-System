<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'verified_at',
        'verified_by',
        'expiry_date',
        'rejection_reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'expiry_date' => 'date',
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

    /**
     * Only a verified, unexpired document counts toward folder categorisation.
     *
     * Expiry matters in practice: medical certificates and police clearances go
     * stale, and a candidate whose clearance lapsed while waiting for a posting
     * is not actually deployable.
     */
    public function countsAsComplete(): bool
    {
        if ($this->status !== 'verified') {
            return false;
        }

        return is_null($this->expiry_date) || ! $this->expiry_date->isPast();
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
