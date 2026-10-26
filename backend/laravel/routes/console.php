<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Requires a cron entry on the server running Laravel's scheduler once a
| minute:
|
|   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
|
*/

/*
 * Runs before the office opens, so HR finds the day's expiry warnings already
 * waiting rather than discovering a lapsed clearance mid-deployment.
 */
Schedule::command('empower:check-expiring-documents --days=30')
    ->dailyAt('06:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

Schedule::command('empower:check-document-storage')
    ->weeklyOn(0, '02:00')
    ->timezone('Asia/Manila')
    ->withoutOverlapping();

/*
 * Expired password reset tokens are refused anyway; this only stops them
 * piling up. Each is a hash, never the link itself.
 */
Schedule::command('auth:clear-resets')
    ->dailyAt('03:00')
    ->timezone('Asia/Manila');

/*
 * Sign-in tokens past their lifetime are refused anyway (and deleted when
 * presented); this removes the ones nobody presents again - a browser simply
 * closed. Kept a day past expiry, as Sanctum suggests. Tokens are stored as
 * hashes, so nothing usable is held until then.
 */
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:15')
    ->timezone('Asia/Manila');
