<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeat for the admin "Cron Jobs" settings page: proves the server cron is
// actually firing schedule:run. Read by CronApiController.
Schedule::call(function () {
    Cache::put('cron_last_heartbeat', now()->toDateTimeString(), 172800);
})->everyMinute()->name('cron-heartbeat');

Schedule::command('cart:notification')->everyMinute();

// Applies scheduled maintenance windows (flips *_mode on/off at start/end).
Schedule::command('maintenance:apply')->everyMinute();

// Auto-publishes home layout drafts at their scheduled time.
Schedule::command('home-layout:publish-scheduled')->everyMinute();

// Turns the popup offer off once its scheduled end time has passed.
Schedule::command('popup:apply-schedule')->everyMinute();

// Promotes a queued spin-wheel campaign on its start date (and switches off the one it
// replaces), plus retires campaigns past their end date.
Schedule::command('spin-wheel:apply-schedule')->everyMinute();

// Retires spin-wheel coupons past their end date (deactivates, never deletes).
Schedule::command('promo:expire-spin-codes')->dailyAt('00:30');

Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();
