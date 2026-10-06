<?php

use App\Models\ApiToken;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled jobs. On HostingRaja add ONE cron entry (cPanel > Cron Jobs), every 5 minutes:
 *   php /home/USER/smartheart/artisan schedule:run >> /dev/null 2>&1
 */

// Daily encrypted database backup at 01:30 IST.
Schedule::command('shi:backup')->dailyAt('01:30');

// Remove expired sign-in tokens.
Schedule::call(fn () => ApiToken::where('expires_at', '<', now())
    ->orWhere('last_used_at', '<', now()->subMinutes(config('smartheart.security.idle_minutes')))
    ->delete())->hourly();
