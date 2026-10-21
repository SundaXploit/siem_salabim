<?php

use App\Services\AlertIngestionCoordinator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── SIEM Scheduler ─────────────────────────────────────────────────────────
// Route every trigger through the same cooldown/status coordinator. The
// queue worker still performs all OpenSearch I/O.
Schedule::call(function (AlertIngestionCoordinator $ingestion): void {
    $ingestion->requestLatestFetch('scheduler');
})
    ->everyMinute()
    ->name('siem:dispatch-alert-fetch')
    ->withoutOverlapping(5);

// Re-scan today and previous local calendar days at a lower cadence. This
// closes the event-time gap for documents indexed after the normal overlap.
Schedule::call(function (AlertIngestionCoordinator $ingestion): void {
    $ingestion->requestReconciliationFetch('scheduler-reconcile');
})
    ->cron('17 */6 * * *')
    ->timezone('Asia/Jakarta')
    ->name('siem:dispatch-alert-reconciliation')
    ->withoutOverlapping(30);

// Check daily. The command reads the admin-defined refresh interval from
// Settings (with AMANAI_REFRESH_DAYS only as a server-side fallback).
Schedule::command('siem:refresh-dashboard-insight')
    ->dailyAt('02:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
