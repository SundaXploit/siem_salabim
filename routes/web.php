<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\BulkAlertController;
use App\Http\Controllers\BulkNotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PermanentAlertIgnoreController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SessionActivityController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TriageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ─── Root → redirect to dashboard ────────────────────────────────────────────
Route::get('/', fn () => redirect('/dashboard'));

// ─── Breeze Auth routes ───────────────────────────────────────────────────────
require __DIR__.'/auth.php';

Route::post('/session/activity', SessionActivityController::class)
    ->middleware(['auth', 'throttle:12,1'])->name('session.activity');

// ─── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware(['auth', 'can:alerts.view'])->group(function () {

    // SOC overview and the separate operational alert queue.
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/dashboard/insight/refresh', [DashboardController::class, 'refreshInsight'])->middleware(['can:alerts.analyze', 'throttle:10,1'])->name('dashboard.insight.refresh');
    Route::get('/live-alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::get('/live-alerts/triage/detail', [BulkAlertController::class, 'show'])->name('alerts.bulk-detail');
    Route::post('/live-alerts/triage/ai-analysis', [BulkAlertController::class, 'analyse'])
        ->middleware(['can:alerts.analyze', 'throttle:10,1'])->name('alerts.bulk-ai-analysis');

    // Alert detail
    Route::get('/alerts/{alert}', [AlertController::class, 'show'])->middleware('can:view,alert')->name('alerts.show');
    Route::post('/alerts/{alert}/ai-analysis', [AlertController::class, 'analyseWithAi'])
        ->middleware(['can:analyze,alert', 'throttle:10,1'])
        ->name('alerts.ai-analysis');

    // Triage
    Route::post('/live-alerts/triage', [TriageController::class, 'bulk'])->name('alerts.bulk-triage');
    Route::post('/live-alerts/triage/notify', [BulkNotificationController::class, 'send'])
        ->middleware(['can:alerts.notify', 'throttle:6,1,manual-notifications:'])->name('alerts.bulk-notify');
    Route::post('/live-alerts/triage/notify/preview', [BulkNotificationController::class, 'preview'])
        ->middleware(['can:alerts.notify', 'throttle:30,1'])->name('alerts.bulk-notify-preview');
    Route::post('/alerts/{alert}/acknowledge', [TriageController::class, 'acknowledge'])->middleware('can:acknowledge,alert')->name('alerts.acknowledge');
    Route::post('/alerts/{alert}/ignore', [TriageController::class, 'ignore'])->middleware('can:ignore,alert')->name('alerts.ignore');

    // Notifications require the matching action privilege and agent access.
    Route::post('/alerts/{alert}/notify', [NotificationController::class, 'send'])->middleware(['can:notify,alert', 'throttle:6,1,manual-notifications:'])->name('alerts.notify');
    Route::get('/alerts/{alert}/notifications/{operationId}', [NotificationController::class, 'status'])->whereUuid('operationId')->middleware('can:notify,alert')->name('alerts.notification-status');
    Route::get('/alerts/{alert}/template', [NotificationController::class, 'template'])->middleware('can:notify,alert')->name('alerts.template');

    // Notification templates management — admin only (enforced in controller)
    Route::get('/notification-templates', [NotificationController::class, 'templatesList'])->middleware('admin')->name('templates.list');
    Route::post('/notification-templates', [NotificationController::class, 'saveTemplate'])->middleware('admin')->name('templates.save');
    Route::delete('/notification-templates/{template}', [NotificationController::class, 'deleteTemplate'])->middleware('admin')->name('templates.delete');

    // Leaderboard
    Route::get('/leaderboard', [LeaderboardController::class, 'index'])->name('leaderboard.index');

    // History
    Route::get('/history/alerts', [HistoryController::class, 'alerts'])->name('history.alerts');
    Route::get('/analyst-log', [HistoryController::class, 'analystLog'])->name('analyst-log.index');
    Route::get('/history/notifications', fn (Request $request) => redirect()->route(
        'analyst-log.index', $request->only('search', 'action', 'date_from', 'date_to', 'page'), 301,
    ))->name('history.notifications');
    Route::get('/history/activity', [HistoryController::class, 'activity'])->middleware('admin')->name('history.activity');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Reject non-admins before resolving settings object IDs.
    Route::middleware('admin')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings/notification-templates', [NotificationController::class, 'saveTemplate'])->name('settings.templates.save');
        Route::delete('/settings/notification-templates/{template}', [NotificationController::class, 'deleteTemplate'])->name('settings.templates.delete');
        Route::post('/settings/permanent-ignores', [PermanentAlertIgnoreController::class, 'store'])->middleware('admin')->name('settings.permanent-ignores.store');
        Route::delete('/settings/permanent-ignores/{ignoreRule}', [PermanentAlertIgnoreController::class, 'destroy'])->middleware('admin')->name('settings.permanent-ignores.destroy');
        Route::post('/settings/opensearch', [SettingController::class, 'updateOpenSearch'])->name('settings.opensearch');
        Route::post('/settings/telegram', [SettingController::class, 'updateTelegram'])->name('settings.telegram');
        Route::post('/settings/telegram/automatic-notifications', [SettingController::class, 'updateAutomaticNotifications'])->middleware('admin')->name('settings.telegram.automatic');
        Route::get('/settings/telegram/agents', [SettingController::class, 'telegramAgents'])->middleware('throttle:30,1')->name('settings.telegram.agents');
        Route::post('/settings/test/opensearch', [SettingController::class, 'testOpenSearch'])->name('settings.test.opensearch');
        Route::post('/settings/test/telegram', [SettingController::class, 'testTelegram'])->name('settings.test.telegram');
        Route::post('/settings/ai', [SettingController::class, 'updateAiInsight'])->name('settings.ai');
        Route::post('/settings/alerts', [SettingController::class, 'updateAlerts'])->name('settings.alerts');
        Route::post('/settings/test/ai', [SettingController::class, 'testAiInsight'])->name('settings.test.ai');
        Route::post('/settings/ai/models', [SettingController::class, 'aiModels'])->middleware('throttle:10,1')->name('settings.ai.models');

        // User management (admin only)
        Route::get('/settings/users/agents', [SettingController::class, 'userAgents'])->middleware('admin')->name('settings.users.agents');
        Route::post('/settings/users', [SettingController::class, 'createUser'])->name('settings.users.create');
        Route::patch('/settings/users/{user}', [SettingController::class, 'updateUser'])->name('settings.users.update');
        Route::patch('/settings/users/{user}/status', [SettingController::class, 'updateUserStatus'])->name('settings.users.status');
        Route::delete('/settings/users/{user}', [SettingController::class, 'deleteUser'])->name('settings.users.delete');

        // Manual trigger fetch
        Route::post('/settings/fetch-now', [SettingController::class, 'manualFetch'])->name('settings.fetch-now');

        // Data management — flexible POST (format, scope, period)
        Route::get('/settings/data/preview', [SettingController::class, 'previewData'])->name('settings.data.preview');
        Route::post('/settings/data/backup', [SettingController::class, 'backupData'])->name('settings.data.backup');
        Route::post('/settings/data/delete', [SettingController::class, 'deleteData'])->name('settings.data.delete');
        Route::post('/settings/data/backup-and-delete', [SettingController::class, 'backupAndDelete'])->name('settings.data.backup-delete');
        Route::get('/settings/data/exports/{dataExport}/status', [SettingController::class, 'dataExportStatus'])->name('settings.data.exports.status');
        Route::post('/settings/data/exports/{dataExport}/authorize-download', [SettingController::class, 'authorizeDataExportDownload'])->middleware('throttle:5,1')->name('settings.data.exports.authorize-download');
        Route::get('/settings/data/exports/{dataExport}/download', [SettingController::class, 'downloadDataExport'])->middleware('signed')->name('settings.data.exports.download');
        Route::post('/settings/data/exports/{dataExport}/purge', [SettingController::class, 'purgeDataExport'])->name('settings.data.exports.purge');
    });

    // API endpoints for Alpine.js polling
    Route::prefix('api')->group(function () {
        Route::get('/alerts/new-count', [AlertController::class, 'newCount'])->name('api.alerts.new-count');
        Route::get('/alerts/latest', [AlertController::class, 'latest'])->name('api.alerts.latest');
        Route::get('/leaderboard', [LeaderboardController::class, 'data'])->name('api.leaderboard');
    });
});
