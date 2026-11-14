<?php

namespace App\Jobs;

use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Services\AutomaticAlertNotifier;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAutomaticAlertNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'automatic-telegram:'.$this->notificationId;
    }

    public function handle(AutomaticAlertNotifier $notifier, TelegramService $telegram): void
    {
        $delivery = NotificationLog::with('alert')->find($this->notificationId);
        if (! $delivery || ! $delivery->automatic_key || $delivery->delivery_state !== 'pending') {
            return;
        }
        $settings = $notifier->settings();
        $alert = $delivery->alert;
        $routes = $alert ? Configuration::telegramDestinationsForAgent($alert->agent_name) : [];
        // Recheck the saved policy at send time, including removed organization mappings.
        if (! $settings['enabled'] || ! $alert || $alert->status !== 'new'
            || $alert->rule_level <= $settings['above_level']
            || ! in_array($delivery->chat_id, array_column($routes, 'chat_id'), true)) {
            NotificationLog::whereKey($delivery->id)->where('delivery_state', 'pending')->update(['delivery_state' => 'cancelled']);

            return;
        }
        $message = $notifier->message($alert, $settings['base_url']);
        // Claim durably before the network call. An uncertain delivery must never be sent again automatically.
        $claimed = $notifier->claimDelivery($delivery, $alert, $message, $settings['daily_limit']);
        if (! $claimed) {
            return;
        }
        try {
            $result = $telegram->sendMessage($delivery->chat_id, $message);
            $delivery->update([
                'delivery_state' => $result['success'] ? 'sent' : (! empty($result['delivery_unknown']) ? 'unconfirmed' : 'failed'),
                'response_status' => $result['status'] ?? 0,
            ]);
        } catch (\Throwable $exception) {
            $delivery->update(['delivery_state' => 'unconfirmed', 'response_status' => 0]);
            Log::warning('[Automatic Telegram] Delivery not confirmed; automatic resend suppressed.', [
                'notification_id' => $delivery->id, 'exception_class' => $exception::class,
            ]);
        }
    }
}
