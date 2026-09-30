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
