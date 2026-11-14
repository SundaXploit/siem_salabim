<?php

namespace App\Services;

use App\Jobs\SendAutomaticAlertNotification;
use App\Models\Alert;
use App\Models\Configuration;
use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutomaticAlertNotifier
{
    public function settings(): array
    {
        return [
            'enabled' => filter_var(Configuration::get('telegram_auto_enabled', false), FILTER_VALIDATE_BOOL),
            'above_level' => (int) Configuration::get('telegram_auto_above_level', 13),
            'daily_limit' => (int) Configuration::get('telegram_auto_daily_limit', 2),
            'base_url' => rtrim((string) Configuration::get('telegram_auto_base_url', config('app.url')), '/'),
        ];
    }

    /** Persist delivery intent in the same transaction as newly imported alerts. */
    public function prepare(iterable $alerts): array
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return [];
        }
        $destinations = Configuration::getTelegramDestinations();
        $ids = [];
        foreach ($alerts as $alert) {
            if ($alert->rule_level <= $settings['above_level'] || $alert->status !== 'new') {
                continue;
            }
            foreach (Configuration::telegramDestinationsForAgent($alert->agent_name, $destinations) as $destination) {
                $delivery = NotificationLog::firstOrCreate([
                    'automatic_key' => hash('sha256', $alert->id.':'.$destination['chat_id']),
                ], [
                    'alert_id' => $alert->id,
                    'user_id' => null,
                    'chat_id' => $destination['chat_id'],
                    'message' => $this->message($alert, $settings['base_url']),
                    'response_status' => null,
                    'delivery_state' => 'pending',
                    'sent_at' => now(),
                ]);
                if ($delivery->wasRecentlyCreated) {
                    $ids[] = $delivery->id;
                }
            }
        }

        return $ids;
    }

    /** Recover committed intents after a queue outage. */
    public function dispatchPending(?array $ids = null): void
    {
        $pending = NotificationLog::where('delivery_state', 'pending')->whereNotNull('automatic_key');
        if ($ids !== null) {
            $pending->whereIn('id', $ids);
        }
        foreach ($pending->orderBy('id')->limit(500)->pluck('id') as $id) {
            try {
                SendAutomaticAlertNotification::dispatch((int) $id);
            } catch (\Throwable $exception) {
                Log::warning('[Automatic Telegram] Delivery remains pending; scheduler will retry dispatch.', [
                    'notification_id' => $id, 'exception_class' => $exception::class,
                ]);
                break;
            }
        }
    }

    /** Claim a delivery and reserve one logical alert per Rule ID + agent on its sending day. */
    public function claimDelivery(NotificationLog $delivery, Alert $alert, string $message, int $dailyLimit): bool
    {
        return DB::transaction(function () use ($delivery, $alert, $message, $dailyLimit): bool {
            $claimed = NotificationLog::whereKey($delivery->id)->where('delivery_state', 'pending')
                ->update(['delivery_state' => 'sending', 'message' => $message, 'sent_at' => now()]);
            if (! $claimed) {
                return false;
            }

            // Hash exact values so case-insensitive database collations cannot combine different agents/rules.
            $groupKey = hash('sha256', json_encode([
                $alert->source_cluster, $alert->rule_id, $alert->agent_name,
            ], JSON_THROW_ON_ERROR));
            $day = CarbonImmutable::now('Asia/Jakarta')->toDateString();
            $quotas = DB::table('automatic_notification_quotas');
            $quotas->insertOrIgnore([
                'group_key' => $groupKey, 'quota_day' => $day, 'event_keys' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $quota = $quotas->where('group_key', $groupKey)->where('quota_day', $day)->lockForUpdate()->first();
            $events = json_decode($quota->event_keys, true, 512, JSON_THROW_ON_ERROR);
            $eventKey = $alert->source_identity_hash;
            if (in_array($eventKey, $events, true)) {
                // All mapped destinations for an admitted alert share the same slot.
                return true;
            }
            if (count($events) >= $dailyLimit) {
                NotificationLog::whereKey($delivery->id)->update(['delivery_state' => 'daily_limited']);

                return false;
            }
            $events[] = $eventKey;
            $quotas->where('id', $quota->id)->update([
                'event_keys' => json_encode($events, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    public function message(Alert $alert, string $baseUrl): string
    {
        $timestamp = $alert->first_seen_at ? $alert->first_seen_at->copy()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB' : '-';
        try {
            $sourceTime = $alert->raw_data['@timestamp'] ?? $alert->raw_data['timestamp'] ?? null;
            if (is_string($sourceTime) && $sourceTime !== '') {
                $timestamp = CarbonImmutable::parse($sourceTime)->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB';
            }
        } catch (\Throwable) {
            // A malformed source timestamp does not change the stored event.
        }
        $url = $baseUrl.route('alerts.show', $alert->id, false);
        $fields = [
            'Rule ID' => $alert->rule_id ?: '-',
            'Rule level' => (string) $alert->rule_level,
            'Agent' => $alert->agent_name ?: '-',
            'Waktu' => $timestamp ?: '-',
            'Detail alert' => $url,
        ];
        $plain = implode("\n", array_map(fn ($key, $value) => $key.': '.$value, array_keys($fields), $fields));
        // Leave room for every required field and the full detail URL in Telegram's 4096-character limit.
        $description = Str::limit($alert->rule_description ?: '-', max(0, 3900 - mb_strlen($plain)), '…');
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lines = ['<b>SIEM Salabim · Alert baru</b>', '', '<b>Deskripsi:</b> '.$escape($description)];
        foreach ($fields as $label => $value) {
            $lines[] = '<b>'.$label.':</b> '.$escape($value);
        }

        return implode("\n", $lines);
    }
}
