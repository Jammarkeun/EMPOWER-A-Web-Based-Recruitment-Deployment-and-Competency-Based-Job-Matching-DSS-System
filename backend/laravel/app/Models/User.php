<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function isStaff(): bool
    {
        return in_array($this->user_type, ['admin', 'hr'], true);
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
