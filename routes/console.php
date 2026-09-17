<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── SIEM Scheduler ─────────────────────────────────────────────────────────
// Run every 1 minute (cron minimum). For 30s polling, run twice per minute.
Schedule::command('siem:fetch-alerts')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Check daily. The command reads the admin-defined refresh interval from
// Settings (with AMANAI_REFRESH_DAYS only as a server-side fallback).
Schedule::command('siem:refresh-dashboard-insight')
    ->dailyAt('02:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
