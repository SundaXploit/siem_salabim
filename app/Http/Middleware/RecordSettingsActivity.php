<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\DataExport;
use App\Models\PermanentAlertIgnore;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordSettingsActivity
{
    private const ACTIONS = [
        'profile.update' => 'profile_updated',
        'password.update' => 'password_changed',
        'settings.index' => 'settings_opened',
        'settings.permanent-ignores.store' => 'permanent_ignore_added',
        'settings.permanent-ignores.destroy' => 'permanent_ignore_removed',
        'settings.opensearch' => 'opensearch_updated',
        'settings.telegram' => 'telegram_updated',
        'settings.telegram.automatic' => 'automatic_notifications_updated',
        'settings.ai' => 'ai_updated',
        'settings.alerts' => 'alerts_updated',
        'settings.test.opensearch' => 'opensearch_tested',
        'settings.test.telegram' => 'telegram_tested',
        'settings.test.ai' => 'ai_tested',
        'settings.ai.models' => 'ai_models_loaded',
        'settings.telegram.agents' => 'agents_loaded',
        'settings.users.create' => 'user_created',
        'settings.users.agents' => 'agents_loaded',
        'settings.users.update' => 'user_updated',
        'settings.users.delete' => 'user_deleted',
        'settings.fetch-now' => 'fetch_requested',
        'settings.data.preview' => 'data_previewed',
        'settings.data.backup' => 'export_requested',
        'settings.data.backup-delete' => 'export_requested',
        'settings.data.delete' => 'data_delete_requested',
        'settings.data.exports.authorize-download' => 'export_authorized',
        'settings.data.exports.download' => 'export_downloaded',
        'settings.data.exports.purge' => 'purge_requested',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        // Export status is polled in the background; it is not a user action.
        if (! $actor || ! $request->routeIs('settings.*', 'profile.update', 'password.update') || $request->routeIs('settings.data.exports.status')) {
            return $next($request);
        }
        $route = $request->route()->getName();
        $action = self::ACTIONS[$route] ?? 'settings_action';
        // Record field names only. Request values and response bodies may hold
        // credentials, tokens, passwords, or signed download URLs.
        $context = ['route' => $route, 'fields' => array_values(array_intersect([
            'opensearch_host', 'opensearch_username', 'opensearch_password', 'opensearch_index', 'min_alert_level',
            'telegram_bot_token', 'telegram_destinations', 'telegram_chat_ids',
            'telegram_auto_enabled', 'telegram_auto_above_level', 'telegram_auto_daily_limit',
            'ai_provider', 'ai_api_format', 'ai_base_url', 'ai_api_key', 'ai_model', 'ai_snapshot_limit', 'ai_refresh_days',
            'name', 'email', 'role', 'agent_keys', 'privileges', 'password', 'current_password', 'rule_id', 'format', 'scope', 'period_mode', 'period_value', 'period_unit', 'date_from', 'date_to',
        ], array_keys($request->all())))];
        if ($request->routeIs('settings.users.create', 'settings.users.update') && $request->has('_access_settings')) {
            $context['fields'] = array_values(array_unique([...$context['fields'], 'agent_keys', 'privileges']));
        }
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            ActivityLog::record($action, $actor, $this->subject($request, $actor), 'failed', $context);
            throw $exception;
        }
        $newFlash = $request->session()->get('_flash.new', []);
        $failed = $response->getStatusCode() >= 400 || array_intersect(['error', 'errors'], $newFlash) !== [];
        if ($response instanceof JsonResponse) {
            $failed = $failed || (($response->getData(true)['success'] ?? null) === false);
        }
        $subject = $this->subject($request, $actor);
        if (! $failed && $route === 'settings.users.create') {
            $created = User::where('email', $request->input('email'))->first();
            $subject = $created ? 'User #'.$created->id.' · '.$created->name : null;
        }
        ActivityLog::record($action, $actor, $subject, $failed ? 'failed' : 'success', $context);
        if (! $failed && $route === 'settings.users.update' && $request->filled('password')) {
            ActivityLog::record('password_changed', $actor, $subject, 'success', ['route' => $route]);
        }

        return $response;
    }

    private function subject(Request $request, User $actor): ?string
    {
        // This middleware also surrounds admin checks and route binding. A denied
        // request can still contain unbound IDs; never resolve those for logging.
        $target = $request->route('user');
        $subject = $target instanceof User ? 'User #'.$target->id.' · '.$target->name : null;
        if ($request->routeIs('profile.update', 'password.update')) {
            $subject = 'Akun sendiri · User #'.$actor->id;
        }
        if (($export = $request->route('dataExport')) instanceof DataExport) {
            $subject = 'Ekspor #'.$export->id;
        }
        if (($ignoreRule = $request->route('ignoreRule')) instanceof PermanentAlertIgnore) {
            $subject = 'Ignore permanen #'.$ignoreRule->id.($ignoreRule->rule_id !== null ? ' · Rule ID '.$ignoreRule->rule_id : ' · Aturan lama');
        } elseif ($request->routeIs('settings.permanent-ignores.store') && is_string($request->input('rule_id')) && preg_match('/\A[0-9]{1,64}\z/', $request->input('rule_id'))) {
            $subject = 'Ignore permanen · Rule ID '.$request->input('rule_id');
        }

        return $subject;
    }
}
