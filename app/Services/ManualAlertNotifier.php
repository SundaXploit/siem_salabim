<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\ManualNotificationOperation;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ManualAlertNotifier
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function send(Alert $alert, User $actor, string $operationId, string $message, array $files = []): array
    {
        $created = $this->prepare($alert, $actor, $operationId, $message, $files);
        if ($created) {
            foreach (NotificationLog::where('manual_operation_id', $operationId)->orderBy('id')->get() as $log) {
                $this->deliver($log, $alert, $actor, $files);
            }
        }

        return $this->result($operationId, ! $created);
    }

    public function prepare(Alert $alert, User $actor, string $operationId, string $message, array $files = []): bool
    {
        $fingerprint = hash('sha256', json_encode([
            $alert->id, $actor->id, $message,
            array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($alert, $actor, $operationId, $fingerprint, $message, $files): bool {
            // The same lock order as triage/deactivation. Never hold locks during network I/O.
            $user = User::lockForAction($actor->id);
            $currentAlert = Alert::whereKey($alert->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('notify', $currentAlert);
            $existing = ManualNotificationOperation::find($operationId);
            if ($existing) {
                $this->assertPayload($existing, $alert, $actor, $fingerprint);

                return false;
            }

            $destinations = Configuration::telegramDestinationsForAgent($currentAlert->agent_scope_key);
            if ($destinations === []) {
                return false;
            }

            $operation = ManualNotificationOperation::firstOrCreate(['id' => $operationId], [
                'alert_id' => $alert->id, 'user_id' => $actor->id, 'payload_hash' => $fingerprint,
            ]);
            $this->assertPayload($operation, $alert, $actor, $fingerprint);
            if (! $operation->wasRecentlyCreated) {
                return false;
            }

            foreach ($destinations as $destination) {
                NotificationLog::create([
                    'manual_operation_id' => $operationId, 'alert_id' => $alert->id, 'user_id' => $actor->id,
                    'chat_id' => $destination['chat_id'], 'message' => $message, 'response_status' => 0,
                    'delivery_state' => 'pending', 'sent_at' => now(),
                    'delivery_details' => [
                        'message' => ['state' => 'pending'],
                        'evidence' => array_map(fn ($file) => ['name' => $file->getClientOriginalName(), 'state' => 'pending'], $files),
                    ],
                ]);
            }

            return true;
        }, 3);
    }

    public function deliverQueued(int $deliveryId): void
    {
        $log = NotificationLog::whereKey($deliveryId)->whereNotNull('manual_operation_id')->whereNull('automatic_key')
            ->where('delivery_state', 'pending')->first();
        if (! $log || isset($log->delivery_details['bulk_group']) || ($log->delivery_details['evidence'] ?? []) !== []) {
            return;
        }
        $alert = $log->alert;
        $actor = $log->user;
        if ($alert && $actor) {
            $this->deliver($log, $alert, $actor, []);
        }
    }

    private function assertPayload(ManualNotificationOperation $operation, Alert $alert, User $actor, string $hash): void
    {
        abort_unless((int) $operation->alert_id === $alert->id && (int) $operation->user_id === $actor->id
            && hash_equals($operation->payload_hash, $hash), 409,
            'ID operasi sudah dipakai untuk alert, pengguna, pesan, atau lampiran yang berbeda. Muat ulang untuk membuat pengiriman baru.');
    }

    private function deliver(NotificationLog $log, Alert $alert, User $actor, array $files): void
    {
        $claimed = DB::transaction(function () use ($log, $alert, $actor): bool {
            $user = User::lockForAction($actor->id);
            $currentAlert = Alert::whereKey($alert->id)->lockForUpdate()->first();
            $destinations = $currentAlert ? Configuration::telegramDestinationsForAgent($currentAlert->agent_scope_key) : [];
            if (! $user || ! $currentAlert || $user->cannot('notify', $currentAlert)
                || ! in_array($log->chat_id, array_column($destinations, 'chat_id'), true)) {
                $details = $log->delivery_details;
                $details['message'] = ['state' => 'cancelled'];
                $details['evidence'] = array_map(fn ($evidence) => [...$evidence, 'state' => 'not_sent'], $details['evidence']);
                NotificationLog::whereKey($log->id)->where('delivery_state', 'pending')->update([
                    'delivery_state' => 'cancelled', 'delivery_details' => SensitiveDataCipher::encryptJson($details),
                ]);

                return false;
            }

            return NotificationLog::whereKey($log->id)->where('delivery_state', 'pending')->update(['delivery_state' => 'sending']) === 1;
        }, 3);
        if (! $claimed) {
            return;
        }

        // A persisted claim is never automatically reclaimed, even after a crash or timeout.
        // Telegram has no idempotency key; an uncertain result must not trigger a second send.
        $details = $log->delivery_details;
        $details['message'] = ['state' => 'sending'];
        $log->update(['delivery_details' => $details]);
        $messageResult = $this->attempt(fn () => $this->telegram->sendMessage($log->chat_id, $log->message));
        $details['message'] = $this->receipt($messageResult);
        $details['format_fallback'] = (bool) ($messageResult['format_fallback'] ?? false);

        DB::transaction(function () use ($log, $messageResult, $details): void {
            $log->update(['response_status' => $messageResult['status'] ?? 0, 'delivery_details' => $details]);
            if ($messageResult['success']) {
                $label = $this->labels()[$log->chat_id] ?? 'Tujuan Telegram tidak lagi terdaftar';
                AlertTriage::create([
                    'alert_id' => $log->alert_id, 'user_id' => $log->user_id, 'action' => 'notify',
                    'reason' => "Notifikasi dikirim ke {$label}; operasi {$log->manual_operation_id}", 'created_at' => now(),
                ]);
            }
        });

        foreach ($files as $index => $file) {
            if (! $messageResult['success']) {
                $details['evidence'][$index]['state'] = 'not_sent';

                continue;
            }
            $details['evidence'][$index]['state'] = 'sending';
            $log->update(['delivery_details' => $details]);
            $photoResult = $this->attempt(fn () => $this->telegram->sendPhoto($log->chat_id, $file->getRealPath(), $file->getClientOriginalName()));
            $details['evidence'][$index] = ['name' => $file->getClientOriginalName(), ...$this->receipt($photoResult)];
            $log->update(['delivery_details' => $details]);
        }

        $states = [$details['message']['state'], ...array_column($details['evidence'], 'state')];
        $state = in_array('unconfirmed', $states, true) ? 'unconfirmed'
            : ($details['message']['state'] !== 'sent' ? 'failed'
                : (in_array('failed', $states, true) ? 'partial' : 'sent'));
        $log->update(['delivery_state' => $state, 'delivery_details' => $details]);
    }

    private function attempt(callable $send): array
    {
        try {
            return $send();
        } catch (Throwable) {
            // Exception messages can contain the bot token in a URL. Do not expose them.
            return ['success' => false, 'status' => 0, 'delivery_unknown' => true,
                'error' => 'Hasil Telegram belum dapat dipastikan. Periksa chat tujuan sebelum membuat pengiriman baru.'];
        }
    }

    private function receipt(array $result): array
    {
        return [
            'state' => $result['success'] ? 'sent' : (! empty($result['delivery_unknown']) ? 'unconfirmed' : 'failed'),
            'status' => $result['status'] ?? 0,
            'error' => $result['success'] ? null : mb_substr($result['error'] ?? 'Pengiriman gagal.', 0, 500),
        ];
    }

    public function result(string $operationId, bool $replayed = true): array
    {
        $labels = $this->labels();
        $deliveries = NotificationLog::where('manual_operation_id', $operationId)->orderBy('id')->get()
            ->map(fn (NotificationLog $log) => [
                'label' => $labels[$log->chat_id] ?? 'Tujuan Telegram tidak lagi terdaftar',
                'state' => $log->delivery_state, 'details' => array_diff_key($log->delivery_details ?? [], ['bulk_group' => true]),
            ])->all();
        $sent = [];
        $failed = [];
        $evidenceFailed = [];
        $pending = [];
        $formatFallback = false;
        foreach ($deliveries as $delivery) {
            $details = $delivery['details'];
            if (($details['message']['state'] ?? '') === 'sent') {
                $sent[] = $delivery['label'];
            } elseif (in_array($delivery['state'], ['pending', 'sending', 'unconfirmed'], true)) {
                $pending[] = $delivery['label'];
            } else {
                $failed[] = $delivery['label'].': '.($details['message']['error'] ?? 'pengiriman dibatalkan');
            }
            foreach ($details['evidence'] ?? [] as $evidence) {
                if ($evidence['state'] !== 'sent') {
                    $evidenceFailed[] = $delivery['label'].': '.$evidence['name'].' ('.($evidence['error'] ?? 'belum terkirim/dikonfirmasi').')';
                }
            }
            $formatFallback = $formatFallback || ($details['format_fallback'] ?? false);
        }
        $message = $sent === [] ? '' : 'Notifikasi terkirim ke '.count($sent).' tujuan: '.implode(', ', $sent).'.';
        if ($failed !== []) {
            $message .= ' Gagal: '.implode('; ', $failed).'.';
        }
        if ($pending !== []) {
            $message .= ' Belum dikonfirmasi: '.implode(', ', $pending).'.';
        }
        if ($evidenceFailed !== []) {
            $message .= ' Evidence gagal: '.implode('; ', $evidenceFailed).'.';
        }
        if ($formatFallback) {
            $message .= ' Format HTML ditolak Telegram; pesan terkirim sebagai teks biasa.';
        }
        if ($replayed) {
            $message .= ' Request ulang memakai hasil tersimpan; tidak ada pengiriman ulang.';
        }
        if ($deliveries === []) {
            $message = ManualNotificationOperation::whereKey($operationId)->exists()
                ? 'Riwayat operasi ini sudah dihapus. Request ulang tidak mengirim kembali.'
                : 'Notifikasi tidak dikirim: belum ada tujuan Telegram untuk agent ini. Atur pemetaan agent di Settings → Telegram.';
        }

        return ['operation_id' => $operationId, 'replayed' => $replayed, 'deliveries' => $deliveries,
            'success' => $deliveries !== [] && count(array_filter($deliveries, fn ($delivery) => $delivery['state'] === 'sent')) === count($deliveries),
            'message' => trim($message)];
    }

    private function labels(): array
    {
        return array_column(Configuration::getTelegramDestinations(), 'label', 'chat_id');
    }
}
