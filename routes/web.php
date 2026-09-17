<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

// ─── Root → redirect to dashboard ────────────────────────────────────────────
Route::get('/', fn() => redirect('/dashboard'));

// ─── Breeze Auth routes ───────────────────────────────────────────────────────
require __DIR__ . '/auth.php';

// ─── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    // SOC overview and the separate operational alert queue.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/dashboard/insight/refresh', [DashboardController::class, 'refreshInsight'])->name('dashboard.insight.refresh');
    Route::get('/live-alerts', [AlertController::class, 'index'])->name('alerts.index');

    // Alert detail
    Route::get('/alerts/{alert}', [AlertController::class, 'show'])->name('alerts.show');
    Route::post('/alerts/{alert}/ai-analysis', [AlertController::class, 'analyseWithAi'])
        ->middleware('throttle:10,1')
        ->name('alerts.ai-analysis');

    // Triage
    Route::post('/live-alerts/triage', [TriageController::class, 'bulk'])->name('alerts.bulk-triage');
    Route::post('/alerts/{alert}/acknowledge', [TriageController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::post('/alerts/{alert}/ignore',      [TriageController::class, 'ignore'])->name('alerts.ignore');

    // Notifications (all authenticated users can SEND; only admin can manage templates)
    Route::post('/alerts/{alert}/notify',  [NotificationController::class, 'send'])->name('alerts.notify');
    Route::get('/alerts/{alert}/template', [NotificationController::class, 'template'])->name('alerts.template');

    // Notification templates management — admin only (enforced in controller)
    Route::get('/notification-templates',                [NotificationController::class, 'templatesList'])->name('templates.list');
    Route::post('/notification-templates',               [NotificationController::class, 'saveTemplate'])->name('templates.save');
    Route::delete('/notification-templates/{template}',  [NotificationController::class, 'deleteTemplate'])->name('templates.delete');

    // Leaderboard
    Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

    // History
    Route::get('/history/alerts',        [HistoryController::class, 'alerts'])->name('history.alerts');
    Route::get('/history/notifications', [HistoryController::class, 'notifications'])->name('history.notifications');
    Route::get('/history/activity',      [HistoryController::class, 'activity'])->name('history.activity');

    // Profile (Breeze default)
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Settings (admin only — middleware enforced in controller)
    Route::get('/settings',                 [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings/opensearch',     [SettingController::class, 'updateOpenSearch'])->name('settings.opensearch');
    Route::post('/settings/telegram',       [SettingController::class, 'updateTelegram'])->name('settings.telegram');
    Route::post('/settings/test/opensearch', [SettingController::class, 'testOpenSearch'])->name('settings.test.opensearch');
    Route::post('/settings/test/telegram',  [SettingController::class, 'testTelegram'])->name('settings.test.telegram');
    Route::post('/settings/ai',             [SettingController::class, 'updateAiInsight'])->name('settings.ai');
    Route::post('/settings/test/ai',        [SettingController::class, 'testAiInsight'])->name('settings.test.ai');

    // User management (admin only)
    Route::post('/settings/users',          [SettingController::class, 'createUser'])->name('settings.users.create');
    Route::patch('/settings/users/{user}',  [SettingController::class, 'updateUser'])->name('settings.users.update');
    Route::delete('/settings/users/{user}', [SettingController::class, 'deleteUser'])->name('settings.users.delete');

    // Manual trigger fetch
    Route::post('/settings/fetch-now', [SettingController::class, 'manualFetch'])->name('settings.fetch-now');

    // Data management — flexible POST (format, scope, period)
    Route::get('/settings/data/preview',    [SettingController::class, 'previewData'])->name('settings.data.preview');
    Route::post('/settings/data/backup',    [SettingController::class, 'backupData'])->name('settings.data.backup');
    Route::post('/settings/data/delete',    [SettingController::class, 'deleteData'])->name('settings.data.delete');
    Route::post('/settings/data/backup-and-delete', [SettingController::class, 'backupAndDelete'])->name('settings.data.backup-delete');

    // API endpoints for Alpine.js polling
    Route::prefix('api')->group(function () {
        Route::get('/alerts/new-count', [AlertController::class, 'newCount'])->name('api.alerts.new-count');
        Route::get('/alerts/latest',    [AlertController::class, 'latest'])->name('api.alerts.latest');
        Route::get('/leaderboard',      [LeaderboardController::class, 'data'])->name('api.leaderboard');
    });
});
