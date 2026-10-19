<?php

namespace App\Models;

use App\Services\Matching\CompetencyScoringService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class Applicant extends Model
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    /** How an application reached the agency; "online" is self-registration. */
    public const SOURCE_CHANNELS = ['walk_in', 'messenger', 'email', 'online'];

    protected $fillable = [
        'applicant_code',
        'source_channel',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'sex',
        'birth_date',
        'civil_status',
        'nationality',
        'height_cm',
        'weight_kg',
        'contact_number',
        'email',
        'present_address',
        'provincial_address',
        'preferred_position',
        'preferred_position_id',
        'availability_date',
        'distance_km',
        'communication_rating',
        'reliability_rating',
        'application_date',
        'remarks',
        'created_by',
        'updated_by',
    ];

    /**
     * current_status and folder_category are deliberately excluded from
     * $fillable. Status only moves through ApplicantLifecycleService so that the
     * transition is validated and historised, and folder_category is derived
     * from verified documents by FolderCategoryService. Allowing either to be
     * mass-assigned would let a request skip those rules.
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'availability_date' => 'date:Y-m-d',
            'application_date' => 'date:Y-m-d',
            'placement_responded_at' => 'datetime',
            'self_registered_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'profile_updated_at' => 'datetime',
            'profile_version' => 'integer',
            'height_cm' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'distance_km' => 'decimal:2',
            'communication_rating' => 'decimal:2',
            'reliability_rating' => 'decimal:2',
        ];
    }

    protected $appends = ['full_name', 'age', 'awaiting_identity_check'];

    /**
     * Registered online and not yet seen at the office.
     *
     * Nobody has checked this person's ID, so their record is held before
     * screening. A record typed in at the counter was verified as it was
     * created, and is never in this state.
     */
    public function getAwaitingIdentityCheckAttribute(): bool
    {
        return ! is_null($this->self_registered_at) && is_null($this->identity_verified_at);
    }

    public function scopeAwaitingIdentityCheck(Builder $query): Builder
    {
        return $query->whereNotNull('self_registered_at')->whereNull('identity_verified_at');
    }

    /**
     * Records that something the matching engine scores has changed, so every
     * ranking scored against the earlier version shows as out of date (audit
     * S-02).
     *
     * One atomic increment rather than a value computed here: two edits saved
     * at the same moment must both count. Call it in the same transaction as
     * the change itself.
     */
    public function markScoredProfileChanged(): void
    {
        static::withTrashed()->whereKey($this->getKey())->update([
            'profile_updated_at' => now(),
            'profile_version' => DB::raw('profile_version + 1'),
        ]);

        $stored = static::withTrashed()->whereKey($this->getKey())
            ->first(['id', 'profile_updated_at', 'profile_version']);

        $this->forceFill([
            'profile_updated_at' => $stored?->profile_updated_at,
            'profile_version' => $stored?->profile_version,
        ])->syncOriginalAttributes(['profile_updated_at', 'profile_version']);
    }

    // ---------------------------------------------------------------- relations

    /**
     * The position the applicant asked for.
     *
     * preferred_position holds the wording shown to them when they chose it, so
     * a record still reads correctly if the position is renamed or withdrawn
     * afterwards. This relation is the reference that survives either.
     */
    public function preferredPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class, 'preferred_position_id');
    }

    public function educations(): HasMany
    {
        return $this->hasMany(ApplicantEducation::class);
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(ApplicantExperience::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(ApplicantSkill::class);
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(ApplicantCertification::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ApplicantRequirement::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class)->orderByDesc('changed_at');
    }

    public function trainingEnrollments(): HasMany
    {
        return $this->hasMany(TrainingEnrollment::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(JobRequestMatch::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Every time the applicant was put forward to a client, newest first. */
    public function endorsements(): HasMany
    {
        return $this->hasMany(Endorsement::class)->orderByDesc('id');
    }

    /** The endorsement still in progress, if any. */
    public function openEndorsement(): HasOne
    {
        return $this->hasOne(Endorsement::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereNull('ended_at')
        );
    }

    public function placementDecisions(): HasMany
    {
        return $this->hasMany(PlacementDecision::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // ------------------------------------------------------------- derived data

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->age;
    }

    /**
     * Total months of prior employment, summed across all recorded experience.
     *
     * Uses the already-loaded collection when the relation is eager loaded, so
     * that ranking a pool of candidates does not issue one query per applicant.
     */
    public function totalMonthsExperience(): int
    {
        if ($this->relationLoaded('experiences')) {
            return (int) $this->experiences->sum('months_experience');
        }

        return (int) $this->experiences()->sum('months_experience');
    }

    /**
     * The applicant's highest attainment, normalised to the vocabulary the
     * competency criteria are written against.
     */
    public function highestEducationLevel(): ?string
    {
        // The scorer's own ranking, so a record and its score agree (CQ-02).
        $ranking = CompetencyScoringService::educationRanks();

        $levels = ($this->relationLoaded('educations') ? $this->educations : $this->educations()->get())
            ->pluck('education_level')
            ->filter()
            ->map(fn ($level) => strtolower(str_replace([' ', '-'], '_', $level)))
            ->filter(fn ($level) => isset($ranking[$level]));

        if ($levels->isEmpty()) {
            return null;
        }

        return $levels->sortByDesc(fn ($level) => $ranking[$level])->first();
    }

    // ------------------------------------------------------------------- scopes

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // PostgreSQL's LIKE is case-sensitive, so searching "santos" would miss
        // "Santos". ILIKE fixes that but does not exist in SQLite, which the
        // test suite uses, hence the driver check.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        /*
         * Word by word (audit UX-06). The whole phrase used to be compared with
         * each column on its own, so "Juan Cruz" - a first name and a last name
         * - matched no single column and found nobody. Now every word has to
         * appear somewhere in the record: "Juan Cruz" finds Juan Dela Cruz, and
         * "Cruz 0917" narrows that to the one with that number. Capped at six
         * words, which is more than any name or code needs.
         */
        $words = array_slice(preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY), 0, 6);

        foreach ($words as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';

            $query->where(function (Builder $q) use ($like, $operator) {
                $q->where('first_name', $operator, $like)
                    ->orWhere('last_name', $operator, $like)
                    ->orWhere('middle_name', $operator, $like)
                    ->orWhere('applicant_code', $operator, $like)
                    ->orWhere('contact_number', $operator, $like)
                    ->orWhere('email', $operator, $like)
                    ->orWhere('preferred_position', $operator, $like);
            });
        }

        return $query;
    }

    public function scopeEvaluable(Builder $query): Builder
    {
        return $query->whereIn('current_status', config('empower.evaluable_applicant_statuses'));
    }
}
