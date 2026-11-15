<?php

namespace App\Http\Controllers;

use App\Jobs\SendBulkAlertNotification;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\AlertTriageService;
use App\Services\BulkAlertNotifier;
use App\Services\QueueExecutionPolicy;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BulkNotificationController extends Controller
{
    public function preview(Request $request, BulkAlertNotifier $notifier)
    {
        $data = $request->validate([
            'alert_ids' => ['required', 'array', 'min:1', 'max:'.AlertTriageService::MAX_BATCH_SIZE],
            'alert_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        return response()->json($notifier->preview($request->user(), $data['alert_ids']))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function send(Request $request, BulkAlertNotifier $notifier, QueueExecutionPolicy $queuePolicy)
    {
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'alert_ids' => ['required', 'array', 'min:1', 'max:'.AlertTriageService::MAX_BATCH_SIZE],
            'alert_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'template_id' => ['nullable', 'integer', Rule::prohibitedIf($request->filled('custom_template') || $request->filled('messages')), Rule::exists('notification_templates', 'id')->where('is_active', true)],
            'custom_template' => ['nullable', 'string', 'min:5', 'max:4096', Rule::prohibitedIf($request->filled('messages'))],
            'messages' => ['nullable', 'array', 'min:1', 'max:100'],
            'messages.*.destination_key' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/', 'distinct'],
            'messages.*.message' => ['required', 'string', 'min:5', 'max:4096'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['required', 'image', 'max:10240'],
        ]);
        if (! $queuePolicy->runsOutsideRequest()) {
            throw ValidationException::withMessages(['alert_ids' => 'Notifikasi massal memerlukan queue asynchronous dan worker default yang berjalan.']);
        }

        $template = filled($data['custom_template'] ?? null) ? $data['custom_template']
            : (! empty($data['template_id']) ? NotificationTemplate::active()->findOrFail($data['template_id'])->body : BulkAlertNotifier::DEFAULT_TEMPLATE);
        $operationIds = $notifier->prepare($request->user(), $data['alert_ids'], $data['operation_id'], $template,
            $data['messages'] ?? [], array_values($request->file('evidence', [])));
        $pending = NotificationLog::whereIn('manual_operation_id', $operationIds)->where('delivery_state', 'pending')->orderBy('id')->get();
        $groups = $pending->groupBy(fn ($log) => $log->delivery_details['bulk_group']['id']);
        $unique = new UniqueLock(app(CacheRepository::class));
        foreach ($groups as $groupId => $members) {
            $job = new SendBulkAlertNotification($groupId, $members->modelKeys());
            if (! $unique->acquire($job)) {
                continue;
            }
            try {
                app(Dispatcher::class)->dispatch($job);
            } catch (\Throwable $exception) {
                $unique->release($job);
                Log::warning('[Bulk notification] Queue dispatch failed', ['group_id' => $groupId, 'exception_class' => $exception::class]);
                $message = 'Sebagian pengiriman belum masuk antrean. Coba ulangi permintaan yang sama; tujuan yang sudah diproses tidak akan dikirim ulang.';

                return $request->expectsJson() ? response()->json(['success' => false, 'message' => $message], 503)
                    : back()->with('error', $message);
            }
        }
        $message = $pending->isEmpty()
            ? 'Operasi ini sudah diproses. Tidak ada pengiriman ulang; periksa riwayat notifikasi.'
            : count($operationIds).' alert digabung menjadi '.$groups->count().' notifikasi Telegram, satu pesan per tujuan. Hasil tercatat pada riwayat masing-masing alert.';

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message, 'pending_deliveries' => $pending->count(), 'notification_count' => $groups->count(), 'next_operation_id' => (string) Str::uuid()])->withHeaders(['Cache-Control' => 'no-store, private'])
            : back()->with('success', $message);
    }
}
