<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\SessionLifetime;
use App\Services\SettingsService;
use App\Support\TextSearch;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Shared for the lifetime of the request.
         *
         * The service snapshots each config value before writing an override
         * over it, so a setting can later be reset to what the config file
         * actually shipped. With a new instance per resolution that snapshot
         * was lost: the provider captured the default at boot, and the
         * controller then reported the override as though it were the default.
         */
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        /*
         * Push runtime settings over the config defaults before anything reads
         * them. Done here so the rest of the application can keep calling
         * config() and still see a value an administrator changed in the
         * settings screen, without every call site knowing about the settings
         * table.
         *
         * Skipped during console commands that run before the table exists, so
         * a first-time `migrate` on an empty database cannot fail here.
         */
        try {
            if (Schema::hasTable('system_settings')) {
                app(SettingsService::class)->applyToConfig();
            }
        } catch (\Throwable) {
            // No database yet, or it is unreachable. The shipped config defaults
            // remain in force, which is the correct fallback.
        }

        /*
         * Fail loudly in development when a relation is used without being
         * eager loaded. Listing applicants with their requirements is an N+1
         * waiting to happen, and the agency's record volume will only grow.
         */
        Model::preventLazyLoading($this->app->environment('local'));

        // Guards against a mass-assignment mistake silently doing nothing, which
        // is precisely how the request_status bug hid during development.
        Model::preventSilentlyDiscardingAttributes($this->app->environment('local', 'testing'));

        // whereContains() / orWhereContains(): search boxes match what was
        // typed, % and _ included, rather than reading them as wildcards.
        TextSearch::register();

        /*
         * A date that reaches JSON on its own - an audit entry's before and
         * after values, an archive snapshot - is written with the local offset
         * rather than converted to UTC. Converted, a calendar date such as a
         * hire date of 23 September became "22 September, 16:00 UTC", and
         * anything reading the first ten characters got the day before. Model
         * attributes keep their own serialisation; resources format explicitly.
         */
        Carbon::serializeUsing(fn (CarbonInterface $date) => $date->toIso8601String());

        /*
         * Sign-in lifetime and idle limit (security audit V-02). Sanctum checks
         * the lifetime itself; SessionLifetime adds the idle limit and removes a
         * token refused for either reason. The token model keeps background
         * polls from counting as activity.
         */
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => app(SessionLifetime::class)->allows($token, $isValid)
        );

        $this->registerAbilities();
        $this->registerRateLimits();
    }

    /**
     * One named limit per public endpoint, each with its own counter.
     *
     * Plain `throttle:10,1` middleware keys every limit on the caller's address
     * alone, so all of them shared one count: the signed document links (120 a
     * minute), sign-in (10) and sign-up (5 an hour) added to the same number,
     * and whichever opened the window set its length. A few documents and a
     * test sign-up then refused every sign-in from that address for an hour.
     * A named limit's counter is keyed by its name as well.
     */
    private function registerRateLimits(): void
    {
        // Read on every request rather than once here, so a changed value -
        // in .env, or in a test - applies without restarting anything.
        $limit = fn (string $name) => (int) config("empower.rate_limits.{$name}");

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute($limit('login_per_minute'))->by($request->ip()));

        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perHour($limit('registrations_per_hour'))
                ->by('created:'.$request->ip())
                ->after(fn ($response) => $response->getStatusCode() === 201),
            Limit::perHour($limit('registration_attempts_per_hour'))
                ->by('attempts:'.$request->ip()),
        ]);

        RateLimiter::for('reset-link', fn (Request $request) => Limit::perHour($limit('reset_link_requests_per_hour'))->by($request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour($limit('password_resets_per_hour'))->by($request->ip()));
        RateLimiter::for('document-files', fn (Request $request) => Limit::perMinute($limit('document_files_per_minute'))->by($request->ip()));

        // Signed-in uploads and scans, per account. These shared one counter per
        // account in the same way, so ten document uploads could hold back the
        // readability check for an hour. The values are unchanged.
        $account = fn (Request $request) => (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        RateLimiter::for('document-scan', fn (Request $request) => Limit::perMinute(20)->by($account($request)));
        RateLimiter::for('violation-report', fn (Request $request) => Limit::perMinute(30)->by($account($request)));
        RateLimiter::for('portal-upload', fn (Request $request) => Limit::perMinute(30)->by($account($request)));
        // Each check is about forty seconds of the recognition service's CPU.
        RateLimiter::for('readability-check', fn (Request $request) => Limit::perHour(10)->by($account($request)));
        RateLimiter::for('profile-photo', fn (Request $request) => Limit::perMinute(20)->by($account($request)));
    }

    /**
     * Abilities that are not tied to a specific model instance.
     */
    private function registerAbilities(): void
    {
        Gate::define('viewDashboard', fn (User $user) => $user->can('dashboard.view'));
        Gate::define('viewArchives', fn (User $user) => $user->can('archives.view'));
        // The retention review list names applicants and their records; a
        // decision on it is part of keeping the archive, hence archives.create.
        Gate::define('viewRetentionReview', fn (User $user) => $user->can('archives.view') && $user->can('applicants.view'));
        Gate::define('recordRetentionDecision', fn (User $user) => $user->can('archives.create'));
        Gate::define('viewAuditLogs', fn (User $user) => $user->can('audit.view'));
        // Reading the user list is users.view; changing accounts needs the
        // specific permission for that change. Only the administrator holds
        // any of these, so nobody's access changes - but the permission matrix
        // now means what it says.
        Gate::define('manageUsers', fn (User $user) => $user->can('users.view'));
        Gate::define('createUsers', fn (User $user) => $user->can('users.create'));
        Gate::define('updateUsers', fn (User $user) => $user->can('users.update'));
        Gate::define('manageRoles', fn (User $user) => $user->can('users.manage_roles'));
        // The document library: whose papers these are (applicants.view) and
        // the documents themselves (requirements.view).
        Gate::define('viewDocuments', fn (User $user) => $user->can('applicants.view') && $user->can('requirements.view'));
        Gate::define('viewReports', fn (User $user) => $user->can('reports.view'));
        Gate::define('exportReports', fn (User $user) => $user->can('reports.export'));
        // Reading settings is part of HR's daily work - knowing which documents
        // are required. Changing them is an administrator decision.
        Gate::define('viewSettings', fn (User $user) => $user->can('settings.view'));
        Gate::define('manageSettings', fn (User $user) => $user->can('settings.update'));
    }
}
