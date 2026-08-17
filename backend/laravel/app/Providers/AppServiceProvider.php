<?php

namespace App\Providers;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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

        $this->registerAbilities();
    }

    /**
     * Abilities that are not tied to a specific model instance.
     */
    private function registerAbilities(): void
    {
        Gate::define('viewDashboard', fn (User $user) => $user->can('dashboard.view'));
        Gate::define('viewArchives', fn (User $user) => $user->can('archives.view'));
        Gate::define('viewAuditLogs', fn (User $user) => $user->can('audit.view'));
        Gate::define('manageUsers', fn (User $user) => $user->can('users.view'));
        Gate::define('viewReports', fn (User $user) => $user->can('reports.view'));
        Gate::define('exportReports', fn (User $user) => $user->can('reports.export'));
        // Reading settings is part of HR's daily work - knowing which documents
        // are required. Changing them is an administrator decision.
        Gate::define('viewSettings', fn (User $user) => $user->can('settings.view'));
        Gate::define('manageSettings', fn (User $user) => $user->can('settings.update'));
    }
}
