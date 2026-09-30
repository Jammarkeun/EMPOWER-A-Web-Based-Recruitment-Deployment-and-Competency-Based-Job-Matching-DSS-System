<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role the agency recruits for.
 *
 * Owned by the client company that has the vacancy, or by nobody when CDE
 * recruits into its own general pool. This is the list an applicant chooses
 * from, so what it contains is a business decision made in the system rather
 * than a list maintained in the front end.
 */
class JobPosition extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'position_code',
        'client_company_id',
        'position_title',
        'description',
        'status',
        'created_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function jobRequests(): HasMany
    {
        return $this->hasMany(JobRequest::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class, 'preferred_position_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * The positions an applicant may actually choose.
     *
     * Two conditions, and the second is the one that is easy to forget: the
     * position must be active, and the company that owns it must be active too.
     * Without the second, a client the agency has stopped supplying would keep
     * offering its jobs to new applicants indefinitely.
     *
     * A position with no company belongs to the agency itself and passes on the
     * first condition alone.
     */
    public function scopeOpenForApplication(Builder $query): Builder
    {
        return $query->active()->where(
            fn (Builder $q) => $q
                ->whereNull('client_company_id')
                ->orWhereHas('company', fn (Builder $c) => $c->active())
        );
    }

    /**
     * How many workers are still wanted for this position right now.
     *
     * Read from the open manpower requests rather than stored, so it cannot
     * drift: the number is a fact about the requests on file, and duplicating it
     * here would create a second answer to the same question.
     */
    public function openings(): int
    {
        return (int) $this->jobRequests()
            ->open()
            ->get(['workers_needed', 'workers_fulfilled'])
            ->sum(fn (JobRequest $request) => $request->remaining_headcount);
    }
}
