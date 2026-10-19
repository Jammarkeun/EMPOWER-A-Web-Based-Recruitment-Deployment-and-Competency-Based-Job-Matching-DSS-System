<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /*
     | Pins permission checks to the "web" guard.
     |
     | Authenticating through Sanctum makes "sanctum" the active guard for the
     | request, and spatie/laravel-permission would then look for permissions
     | registered under that name — finding none, because roles and permissions
     | are seeded under "web". The symptom is every authorisation failing with
     | "There is no permission named X for guard sanctum", but only over HTTP:
     | in the console the active guard is still "web", so the whole test suite
     | and every artisan command passes while the running application refuses
     | everybody.
     |
     | Declaring the guard here keeps one set of permissions covering both the
     | session guard and the API.
     */
    protected string $guard_name = 'web';

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'mobile_number',
        'password',
        'user_type',
        'applicant_id',
        'employee_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * The notification categories that exist, and what they cover.
     *
     * Held here rather than in a config file because the list is derived from
     * the notification classes themselves - a category is only real if
     * something sends it - and this is the model that has to answer questions
     * about them.
     */
    public const NOTIFICATION_CATEGORIES = [
        'application' => 'Application updates',
        'requirements' => 'Document reviews',
        'deployment' => 'Placement and deployment',
        'coordination' => 'Violation reports and coordination',
        'general' => 'Everything else',
    ];

    /**
     * Whether this user still wants to hear about a category.
     *
     * Absent preferences mean yes. Somebody who has never opened the settings
     * screen should receive everything, so "not configured" and "configured to
     * nothing" have to be different answers - which is why the column is
     * nullable rather than defaulting to an empty object.
     *
     * An unrecognised category also returns true. A new kind of notification
     * added later should reach people by default rather than being silently
     * suppressed for every account that saved preferences before it existed.
     */
    public function wantsNotification(string $category): bool
    {
        $preferences = $this->notification_preferences;

        if (! is_array($preferences) || ! array_key_exists($category, $preferences)) {
            return true;
        }

        return (bool) $preferences[$category];
    }

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])));
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Every record whose notifications this login reads (audit S-03, WF-13).
     *
     * A notification is addressed to the record its event concerns: a status
     * change or a deployment to the applicant record, a password change to the
     * login. A portal login reaches the person through `applicant_id`,
     * `employee_id`, or both - employee logins have been linked to the employee
     * record alone - so both links are followed. A staff login is never linked
     * to a person's records and reads only its own.
     *
     * @return list<Model>
     */
    public function notifiableRecords(): array
    {
        if (! $this->isPortalAccount()) {
            return [$this];
        }

        $applicant = $this->applicant ?? $this->employee?->applicant;
        $employee = $this->employee ?? $applicant?->employee;

        return array_values(array_filter([$this, $applicant, $employee]));
    }

    /**
     * The portal login a person's record belongs to, if there is one.
     *
     * The reverse of notifiableRecords(), and resolved the same way: through
     * either link. It is what decides whose notification preferences apply to
     * a notification addressed to an applicant or employee record. It used to
     * look only for `applicant_id`, so an employee login linked to the employee
     * record alone had its muted categories ignored for everything addressed to
     * the applicant record it came from.
     */
    public static function portalAccountFor(Model $record): ?self
    {
        [$applicantId, $employeeId] = match (true) {
            $record instanceof Applicant => [
                $record->getKey(),
                Employee::query()->where('applicant_id', $record->getKey())->value('id'),
            ],
            $record instanceof Employee => [$record->applicant_id, $record->getKey()],
            default => [null, null],
        };

        if ($applicantId === null && $employeeId === null) {
            return null;
        }

        return static::query()
            ->whereIn('user_type', self::PORTAL_TYPES)
            ->where(fn (Builder $q) => $q
                ->when($applicantId, fn (Builder $q) => $q->orWhere('applicant_id', $applicantId))
                ->when($employeeId, fn (Builder $q) => $q->orWhere('employee_id', $employeeId)))
            ->orderBy('id')
            ->first();
    }

    public const PORTAL_TYPES = ['applicant', 'employee'];

    public function isPortalAccount(): bool
    {
        return in_array($this->user_type, self::PORTAL_TYPES, true);
    }

    public function isStaff(): bool
    {
        return in_array($this->user_type, ['admin', 'hr'], true);
    }

    /**
     * A CDE coordinator: an agency employee assigned to one client company.
     *
     * Deliberately not staff in the sense above. A coordinator holds none of
     * HR's permissions and reaches only their own client's records.
     */
    public function isCoordinator(): bool
    {
        return $this->user_type === 'coordinator';
    }

    public function coordinatorAssignments(): HasMany
    {
        // The id breaks ties: a reassignment made within the same second as the
        // previous one must still list newest first on every database engine.
        return $this->hasMany(CoordinatorAssignment::class, 'coordinator_user_id')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id');
    }

    /** The client this coordinator covers now, if any. */
    public function activeCoordinatorAssignment(): HasOne
    {
        return $this->hasOne(CoordinatorAssignment::class, 'coordinator_user_id')->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('status', 'active')->whereNull('unassigned_at')
        );
    }

    /**
     * Deactivated accounts keep their records for audit purposes but must not be
     * able to authenticate, so the check happens at the guard level rather than
     * relying on every controller to remember it.
     */
    public function isActive(): bool
    {
        return (bool) $this->is_active && is_null($this->deleted_at);
    }
}
