<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Configuration;
use App\Models\ManualNotificationOperation;
use App\Models\NotificationTemplate;
use App\Services\ManualAlertNotifier;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected function adminOnly(): void
    {
        if (! auth()->check() || ! auth()->user()->isAdmin()) {
            abort(403, 'Akses hanya untuk Admin.');
        }
    }

    public function send(Request $request, Alert $alert, ManualAlertNotifier $notifier)
    {
        abort_unless($request->user()->can('notify', $alert->fresh()), 403, 'Notifikasi manual hanya dapat dikirim oleh pemilik ACK.');
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'min:5', 'max:4096'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['required', 'image', 'max:10240'],
        ], [
            'operation_id.required' => 'Muat ulang halaman untuk membuat ID pengiriman.',
            'message.required' => 'Pesan tidak boleh kosong.',
            'message.max' => 'Pesan maksimal 4096 karakter.',
            'evidence.max' => 'Maksimal 5 gambar per pengiriman.',
            'evidence.*.image' => 'File evidence harus berupa gambar (jpg, png, gif, webp).',
            'evidence.*.max' => 'Ukuran gambar maksimal 10MB.',
        ]);
        $result = $notifier->send($alert, $request->user(), strtolower($data['operation_id']),
            $data['message'], array_values($request->file('evidence', [])));

        return $request->expectsJson() ? response()->json($result)->withHeaders(['Cache-Control' => 'no-store, private'])
            : back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function status(Request $request, Alert $alert, string $operationId, ManualAlertNotifier $notifier)
    {
        ManualNotificationOperation::whereKey(strtolower($operationId))->where('alert_id', $alert->id)
            ->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json($notifier->result(strtolower($operationId)))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * Get templates and routed destination labels for an alert (AJAX)
     */
    public function template(Alert $alert)
    {
        $templates = NotificationTemplate::allActive()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'category' => $t->category,
            'body' => $t->render($alert),
        ]);

        $defaultBody = $templates->first()['body'] ?? TelegramService::buildTemplate($alert->toArray());

        return response()->json([
            'templates' => $templates,
            'destinations' => array_column(Configuration::telegramDestinationsForAgent($alert->agent_scope_key), 'label'),
            'default_message' => $defaultBody,
        ])->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * Get ALL notification templates list (for Settings management) - Admin only
     */
    public function templatesList()
    {
        $this->adminOnly();

        return response()->json(NotificationTemplate::orderBy('category')->orderBy('name')->get())
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * Save a new or update existing template - Admin only
     */
    public function saveTemplate(Request $request)
    {
        $this->adminOnly();

        $data = $request->validate([
            'id' => 'nullable|integer|exists:notification_templates,id',
            'name' => 'required|string|max:100',
            'category' => 'nullable|string|max:50',
            'body' => 'required|string|min:5|max:4096',
            'is_active' => 'sometimes|required|boolean',
        ]);

        $attributes = [
            'name' => $data['name'],
            'category' => $data['category'] ?? 'General', 'body' => $data['body'],
            'is_active' => $data['is_active'] ?? true,
        ];

        if ($request->filled('id')) {
            $tpl = NotificationTemplate::findOrFail($request->id);
            $attributes['is_active'] = $data['is_active'] ?? $tpl->is_active;
            $tpl->update($attributes);

            return response()->json(['success' => true, 'message' => 'Template diperbarui.', 'template' => $tpl]);
        }

        $tpl = NotificationTemplate::create($attributes);

        return response()->json(['success' => true, 'message' => 'Template disimpan.', 'template' => $tpl]);
    }

    /**
     * Delete a template - Admin only
     */
    public function deleteTemplate(NotificationTemplate $template)
    {
        $this->adminOnly();
        $template->delete();

        return response()->json(['success' => true, 'message' => 'Template dihapus. Riwayat notifikasi tetap tersimpan.']);
    }
}
