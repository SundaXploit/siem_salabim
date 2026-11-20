<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\ManualNotificationOperation;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

class BulkAlertNotifier
{
    public const DEFAULT_TEMPLATE = "<b>Ringkasan triage massal</b>\nAlert: {alert_count}\nID: {alert_ids}\nAgent: {agents}\nRule: {rule_ids}\nLevel maksimum: {max_level}\nWaktu: {timestamp}";

    public function __construct(private readonly TelegramService $telegram) {}

    public function preview(User $actor, array $alertIds): array
    {
        Gate::forUser($actor)->authorize('alerts.notify');
        $alerts = Alert::visibleTo($actor)->whereIn('id', $alertIds)->orderBy('id')->get();
        $this->assertOwned($actor, $alerts, $alertIds);
        $groups = $this->groups($alerts);
        $messages = function (string $body) use ($groups): array {
            $result = [];
            foreach ($groups as $chatId => $group) {
                $result[] = ['destination_key' => $this->destinationKey($chatId, $group['alerts']),
                    'label' => $group['label'], 'alert_count' => $group['alerts']->count(),
                    'message' => $this->render($body, $group['alerts'])];
            }

            return $result;
        };
        $templates = NotificationTemplate::allActive()->map(fn ($template) => [
            'id' => $template->id, 'name' => $template->name,
            'category' => $template->category, 'messages' => $messages($template->body),
        ])->values();

        return ['templates' => $templates, 'default_messages' => $templates->first()['messages'] ?? $messages(self::DEFAULT_TEMPLATE)];
    }

    private function assertOwned(User $actor, Collection $alerts, array $alertIds): void
    {
        if ($alerts->count() !== count($alertIds) || $alerts->contains(fn ($alert) => ! $alert->isAcknowledgedBy($actor))) {
            throw ValidationException::withMessages(['alert_ids' => 'ACK massal terlebih dahulu. Semua alert notifikasi harus sudah ACK milik Anda; pilihan bercampur ditolak seluruhnya.']);
        }
    }

    private function groups(Collection $alerts): array
    {
        $destinations = Configuration::getTelegramDestinations();
        $groups = [];
        foreach ($alerts as $alert) {
            $routes = Configuration::telegramDestinationsForAgent($alert->agent_scope_key, $destinations);
            if ($routes === []) {
                throw ValidationException::withMessages(['alert_ids' => "Alert #{$alert->id} belum memiliki tujuan Telegram. Lengkapi pemetaan agent sebelum mengirim massal."]);
            }
            foreach ($routes as $route) {
                $groups[$route['chat_id']]['label'] = $route['label'];
                $groups[$route['chat_id']]['alerts'][$alert->id] = $alert;
            }
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            $group['alerts'] = collect($group['alerts']);
        }

        return $groups;
    }

    private function destinationKey(string|int $chatId, Collection $alerts): string
    {
        return hash('sha256', json_encode([(string) $chatId, $alerts->pluck('id')->values()->all()], JSON_THROW_ON_ERROR));
    }

    public function prepare(User $actor, array $alertIds, string $batchId, string $template, array $editedMessages = [], array $files = []): array
    {
        $stored = [];
        $created = false;
        try {
            $operationIds = DB::transaction(function () use ($actor, $alertIds, $batchId, $template, $editedMessages, $files, &$stored, &$created): array {
                $created = false;
                $user = User::lockForAction($actor->id);
                Gate::forUser($user)->authorize('alerts.notify');
                $alerts = Alert::visibleTo($user)->whereIn('id', $alertIds)->orderBy('id')->lockForUpdate()->get();
                $this->assertOwned($user, $alerts, $alertIds);
                $groups = $this->groups($alerts);
                $overrides = collect($editedMessages)->keyBy('destination_key');
                $expectedKeys = collect($groups)->map(fn ($group, $chatId) => $this->destinationKey($chatId, $group['alerts']))->sort()->values()->all();
                if ($editedMessages !== [] && ($overrides->count() !== count($editedMessages) || $overrides->keys()->sort()->values()->all() !== $expectedKeys)) {
                    throw ValidationException::withMessages(['messages' => 'Tujuan atau pilihan alert berubah. Buka ulang dialog untuk memuat pesan sesuai pemetaan terbaru.']);
                }
                $messages = [];
                foreach ($groups as $chatId => $group) {
                    $members = $group['alerts'];
                    // Edited messages are already rendered. Do not expand literal braces again.
                    $message = $overrides[$this->destinationKey($chatId, $members)]['message'] ?? $this->render($template, $members);
                    if (mb_strlen($message) < 5 || mb_strlen($message) > 4096) {
                        throw ValidationException::withMessages(['template_id' => 'Pesan gabungan harus berisi 5 sampai 4096 karakter. Gunakan ringkasan lebih singkat atau kurangi pilihan alert; pesan tidak dipecah menjadi beberapa notifikasi.']);
                    }
                    $messages[] = ['chat_id' => (string) $chatId, 'alert_ids' => $members->pluck('id')->values()->all(), 'message' => $message];
                }
                $fingerprintData = ['bulk-summary-v1', $user->id, $alerts->modelKeys(), $template, $messages];
                if ($files !== []) {
                    $fingerprintData[] = array_map(fn ($file) => [$file->getClientOriginalName(), hash_file('sha256', $file->getRealPath())], $files);
                }
                $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
                $operationIds = $alerts->mapWithKeys(fn ($alert) => [$alert->id => Uuid::uuid5(Uuid::NAMESPACE_URL,
                    'siem-bulk-notify:'.$user->id.':'.strtolower($batchId).':'.$alert->id)->toString()])->all();
                $existing = ManualNotificationOperation::whereIn('id', $operationIds)->get();
                foreach ($existing as $operation) {
                    abort_unless((int) $operation->user_id === $user->id
                        && ($operationIds[$operation->alert_id] ?? null) === $operation->id
                        && hash_equals($operation->payload_hash, $fingerprint), 409,
                        'ID operasi sudah digunakan untuk pilihan, template, atau tujuan berbeda. Muat ulang untuk membuat pengiriman baru.');
                }
                if ($existing->isNotEmpty()) {
                    abort_unless($existing->count() === count($operationIds), 409, 'Operasi tidak lengkap. Periksa riwayat sebelum membuat pengiriman baru.');

                    // Never recreate receipts removed by retention or restore a partially purged batch.
                    return array_values($operationIds);
                }

                if ($stored === []) {
                    foreach ($files as $file) {
                        $path = 'telegram-evidence/'.hash('sha256', $user->id.':'.strtolower($batchId)).'/'.Str::uuid().'.enc';
                        $stored[] = ['path' => $path, 'name' => $file->getClientOriginalName(), 'sha256' => hash_file('sha256', $file->getRealPath())];
                        if (! Storage::disk('local')->put($path, SensitiveDataCipher::encryptString(file_get_contents($file->getRealPath())))) {
                            throw ValidationException::withMessages(['evidence' => 'Lampiran belum dapat disimpan dengan aman. Coba kembali.']);
                        }
                    }
                }

                foreach ($operationIds as $alertId => $operationId) {
                    ManualNotificationOperation::create(['id' => $operationId, 'alert_id' => $alertId,
                        'user_id' => $user->id, 'payload_hash' => $fingerprint]);
                }
                foreach ($messages as $message) {
                    $groupId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'siem-bulk-summary:'.$user->id.':'.strtolower($batchId).':'.$fingerprint.':'.$message['chat_id'])->toString();
                    foreach ($message['alert_ids'] as $alertId) {
                        NotificationLog::create([
                            'manual_operation_id' => $operationIds[$alertId], 'alert_id' => $alertId, 'user_id' => $user->id,
                            'delivery_group_key' => hash('sha256', $groupId),
                            'chat_id' => $message['chat_id'], 'response_status' => 0, 'sent_at' => now(), 'delivery_state' => 'pending',
                            // Per-alert history must not expose other agents after an access change.
                            'message' => "Konteks alert #{$alertId} dalam notifikasi gabungan:\n".$this->render($editedMessages === [] ? $template : self::DEFAULT_TEMPLATE, collect([$alerts->find($alertId)])),
                            'delivery_details' => ['message' => ['state' => 'pending'],
                                'evidence' => array_map(fn ($file) => ['name' => $file['name'], 'state' => 'pending'], $stored),
                                'bulk_group' => ['id' => $groupId, 'alert_ids' => $message['alert_ids'], 'message' => $message['message'],
                                    'files' => $stored, 'operation_ids' => array_values($operationIds)]],
                        ]);
                    }
                }

                $created = true;

                return array_values($operationIds);
            }, 3);
            if (! $created) {
                Storage::disk('local')->delete(array_column($stored, 'path'));
            }

            return $operationIds;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete(array_column($stored, 'path'));
            throw $exception;
        }
    }

    public function deliver(string $groupId, array $deliveryIds): void
    {
        $first = NotificationLog::whereIn('id', $deliveryIds)->orderBy('id')->first();
        if (! $first) {
            return;
        }
        $claimed = DB::transaction(function () use ($first, $groupId, $deliveryIds): ?Collection {
            $user = User::lockForAction($first->user_id);
            $group = $first->delivery_details['bulk_group'] ?? [];
            $alerts = Alert::visibleTo($user)->whereIn('id', $group['alert_ids'] ?? [])->orderBy('id')->lockForUpdate()->get();
            $logs = NotificationLog::whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get();
            if ($logs->isEmpty() || $logs->contains(fn ($log) => $log->delivery_state !== 'pending')) {
                return null;
            }
            $destinations = Configuration::getTelegramDestinations();
            $valid = ($group['id'] ?? null) === $groupId && $logs->count() === count($deliveryIds)
                && $alerts->modelKeys() === ($group['alert_ids'] ?? [])
                && $logs->pluck('alert_id')->sort()->values()->all() === $alerts->modelKeys()
                && $logs->every(fn ($log) => $log->manual_operation_id !== null && $log->automatic_key === null
                    && $log->user_id === $user->id && $log->chat_id === $first->chat_id
                    && ($log->delivery_details['bulk_group'] ?? []) === $group)
                && $alerts->every(fn ($alert) => $user->can('notify', $alert)
                    && in_array($first->chat_id, array_column(Configuration::telegramDestinationsForAgent($alert->agent_scope_key, $destinations), 'chat_id'), true));
            foreach ($logs as $log) {
                $details = $log->delivery_details;
                $details['message'] = ['state' => $valid ? 'sending' : 'cancelled'];
                if (! $valid) {
                    $details['evidence'] = array_map(fn ($file) => [...$file, 'state' => 'not_sent'], $details['evidence'] ?? []);
                }
                $log->update(['delivery_state' => $details['message']['state'], 'delivery_details' => $details]);
            }

            return $valid ? $logs : null;
        }, 3);
        if (! $claimed) {
            $this->cleanupEvidence($first->delivery_details['bulk_group'] ?? []);

            return;
        }

        // One persisted claim covers the whole chat. No database locks during network I/O.
        // Sending/uncertain claims are never reclaimed: Telegram has no idempotency key.
        $first = $claimed->first();
        try {
            $result = $this->telegram->sendMessage($first->chat_id, $first->delivery_details['bulk_group']['message']);
        } catch (Throwable) {
            $result = ['success' => false, 'status' => 0, 'delivery_unknown' => true,
                'error' => 'Hasil Telegram belum dapat dipastikan. Periksa chat tujuan sebelum membuat pengiriman baru.'];
        }
        $state = $result['success'] ? 'sent' : (! empty($result['delivery_unknown']) ? 'unconfirmed' : 'failed');
        DB::transaction(function () use ($claimed, $groupId, $result, $state): void {
            User::lockForAction($claimed->first()->user_id);
            $label = array_column(Configuration::getTelegramDestinations(), 'label', 'chat_id')[$claimed->first()->chat_id] ?? 'Tujuan Telegram tidak lagi terdaftar';
            foreach (NotificationLog::whereIn('id', $claimed->modelKeys())->orderBy('id')->lockForUpdate()->get() as $log) {
                if ($log->delivery_state !== 'sending') {
                    continue;
                }
                $details = $log->delivery_details;
                $details['message'] = ['state' => $state, 'status' => $result['status'] ?? 0,
                    'error' => $result['success'] ? null : mb_substr($result['error'] ?? 'Pengiriman gagal.', 0, 500)];
                $details['format_fallback'] = (bool) ($result['format_fallback'] ?? false);
                $log->update(['response_status' => $result['status'] ?? 0, 'delivery_details' => $details]);
                if ($result['success']) {
                    AlertTriage::create(['alert_id' => $log->alert_id, 'user_id' => $log->user_id, 'action' => 'notify',
                        'reason' => "Notifikasi gabungan dikirim ke {$label}; grup {$groupId}; operasi {$log->manual_operation_id}", 'created_at' => now()]);
                }
            }
        }, 3);

        $details = $claimed->first()->fresh()->delivery_details;
        foreach ($details['bulk_group']['files'] ?? [] as $index => $file) {
            if (! $result['success']) {
                $details['evidence'][$index]['state'] = 'not_sent';

                continue;
            }
            $details['evidence'][$index]['state'] = 'sending';
            $this->updateDeliveries($claimed, $details, 'sending');
            $photoResult = $this->sendEvidence($first->chat_id, $file);
            $details['evidence'][$index] = ['name' => $file['name'],
                'state' => $photoResult['success'] ? 'sent' : (! empty($photoResult['delivery_unknown']) ? 'unconfirmed' : 'failed'),
                'status' => $photoResult['status'] ?? 0,
                'error' => $photoResult['success'] ? null : mb_substr($photoResult['error'] ?? 'Lampiran gagal dikirim.', 0, 500)];
            $this->updateDeliveries($claimed, $details, 'sending');
        }
        $states = [$details['message']['state'], ...array_column($details['evidence'] ?? [], 'state')];
        $state = in_array('unconfirmed', $states, true) ? 'unconfirmed'
            : ($details['message']['state'] !== 'sent' ? 'failed' : (in_array('failed', $states, true) ? 'partial' : 'sent'));
        $this->updateDeliveries($claimed, $details, $state);
        $this->cleanupEvidence($details['bulk_group']);
    }

    private function updateDeliveries(Collection $logs, array $details, string $state): void
    {
        DB::transaction(function () use ($logs, $details, $state): void {
            foreach (NotificationLog::whereIn('id', $logs->modelKeys())->orderBy('id')->lockForUpdate()->get() as $log) {
                $log->update(['delivery_details' => $details, 'delivery_state' => $state]);
            }
        }, 3);
    }

    private function sendEvidence(string $chatId, array $file): array
    {
        $temporary = null;
        try {
            if (! str_starts_with($file['path'], 'telegram-evidence/') || ! str_ends_with($file['path'], '.enc')) {
                throw new \RuntimeException('Invalid evidence reference.');
            }
            $contents = SensitiveDataCipher::decryptString(Storage::disk('local')->get($file['path']));
            if ($contents === null || ! hash_equals($file['sha256'], hash('sha256', $contents))) {
                throw new \RuntimeException('Invalid evidence content.');
            }
            $temporary = tempnam(sys_get_temp_dir(), 'siem-evidence-');
            if ($temporary === false || file_put_contents($temporary, $contents) === false) {
                throw new \RuntimeException('Evidence unavailable.');
            }
            unset($contents);
        } catch (Throwable) {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            return ['success' => false, 'status' => 0, 'error' => 'Lampiran terenkripsi tidak tersedia atau gagal diverifikasi.'];
        }
        try {
            return $this->telegram->sendPhoto($chatId, $temporary, $file['name']);
        } catch (Throwable) {
            return ['success' => false, 'status' => 0, 'delivery_unknown' => true, 'error' => 'Hasil pengiriman lampiran belum dapat dipastikan. Periksa chat sebelum mengirim ulang.'];
        } finally {
            unlink($temporary);
        }
    }

    private function cleanupEvidence(array $group): void
    {
        if (($group['files'] ?? []) !== [] && ! NotificationLog::whereIn('manual_operation_id', $group['operation_ids'])
            ->whereIn('delivery_state', ['pending', 'sending'])->exists()) {
            Storage::disk('local')->delete(array_column($group['files'], 'path'));
        }
    }

    private function render(string $template, Collection $alerts): string
    {
        $join = fn (callable $value) => $alerts->map($value)->unique()->implode(', ');
        $agent = fn ($alert) => $alert->agent_name.' ('.$alert->source_cluster.'/'.data_get($alert->raw_data, 'agent.id', '-').')';
        $times = $alerts->sortBy('first_seen_at')->map(fn ($alert) => $alert->first_seen_at?->copy()->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s'))->filter()->values();
        $vars = [
            'alert_count' => $alerts->count(), 'alert_ids' => $join(fn ($alert) => $alert->id),
            'agent_count' => $alerts->pluck('agent_scope_key')->unique()->count(), 'agents' => $join($agent),
            'agent_ids' => $join(fn ($alert) => data_get($alert->raw_data, 'agent.id', '-')),
            'agent_names' => $join(fn ($alert) => $alert->agent_name ?? '-'),
            'rule_ids' => $join(fn ($alert) => $alert->rule_id ?? '-'), 'max_level' => $alerts->max('rule_level'),
            'rule_level' => $join(fn ($alert) => $alert->rule_level ?? '-'),
            'rule_description' => $join(fn ($alert) => $alert->rule_description ?? '-'),
            'source_cluster' => $join(fn ($alert) => $alert->source_cluster ?? '-'),
            'src_ip' => $join(fn ($alert) => $alert->src_ip ?? '-'), 'dst_ip' => $join(fn ($alert) => $alert->dst_ip ?? '-'),
            'agent_ip' => $join(fn ($alert) => data_get($alert->raw_data, 'agent.ip', '-')),
            'wazuh_id' => $join(fn ($alert) => $alert->wazuh_alert_id ?? '-'),
            'timestamp' => $times->isEmpty() ? '-' : collect([$times->first(), $times->last()])->unique()->implode(' – ').' '.\App\Support\LocalTime::label(),
            'alerts' => $alerts->map(fn ($alert) => '#'.$alert->id.' | '.$agent($alert).' | Rule '.$alert->rule_id.' | Level '.$alert->rule_level.' | '.($alert->rule_description ?? '-'))->implode("\n"),
        ];
        $vars['alert_id'] = $vars['alert_ids'];
        $vars['agent_id'] = $vars['agent_ids'];
        $vars['agent_name'] = $vars['agent_names'];
        $vars['rule_id'] = $vars['rule_ids'];
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return strtr($template, $replacements);
    }
}
