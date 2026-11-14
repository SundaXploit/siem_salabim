<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\Configuration;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    protected function adminOnly(): void
    {
        if (!auth()->check() || !auth()->user()->isAdmin()) {
            abort(403, 'Akses hanya untuk Admin.');
        }
    }

    public function send(Request $request, Alert $alert, TelegramService $telegram)
    {
        $request->validate([
            'message' => 'required|string|min:5',
            'evidence' => 'nullable|array',
            'evidence.*' => 'image|max:10240',
        ], [
            'message.required' => 'Pesan tidak boleh kosong.',
            'evidence.array' => 'Format evidence tidak valid.',
            'evidence.*.image' => 'File evidence harus berupa gambar (jpg, png, gif, webp).',
            'evidence.*.max' => 'Ukuran gambar maksimal 10MB.',
        ]);

        // Resolve from the stored alert and admin configuration, never request-supplied recipients or agents.
        $destinations = Configuration::telegramDestinationsForAgent($alert->agent_name);
        if ($destinations === []) {
            return back()->with('error', 'Notifikasi tidak dikirim: belum ada tujuan Telegram untuk agent '.($alert->agent_name ?: 'tanpa nama').'. Atur pemetaan agent di Settings → Telegram.');
        }

        $delivered = [];
        $failures = [];
        $photoFailures = [];
        $formatFallback = false;
        foreach ($destinations as $destination) {
            $chatId = $destination['chat_id'];
            $label = $destination['label'];
            $result = $telegram->sendMessage($chatId, $request->message);
            $photoSuccessCount = 0;
            if ($result['success'] && $request->hasFile('evidence')) {
                foreach ($request->file('evidence') as $file) {
                    $photoResult = $telegram->sendPhoto($chatId, $file->getRealPath(), $file->getClientOriginalName());
                    if ($photoResult['success']) {
                        $photoSuccessCount++;
                    } else {
                        $photoFailures[] = $label.': '.$file->getClientOriginalName().' ('.($photoResult['error'] ?? 'gagal dikirim').')';
                    }
                    gc_collect_cycles();
                }
            }

            NotificationLog::create([
                'alert_id' => $alert->id,
                'user_id' => Auth::id(),
                'chat_id' => $chatId,
                'message' => $request->message.($photoSuccessCount > 0 ? "\n\n📎 [{$photoSuccessCount} Evidence gambar dikirim terpisah]" : ''),
                'response_status' => $result['status'] ?? 0,
                'sent_at' => now(),
            ]);

            if ($result['success']) {
                $delivered[] = $label;
                $formatFallback = $formatFallback || ($result['format_fallback'] ?? false);
                AlertTriage::create([
                    'alert_id' => $alert->id,
                    'user_id' => Auth::id(),
                    'action' => 'notify',
                    'reason' => "Notifikasi dikirim ke {$label} ({$chatId})".($photoSuccessCount > 0 ? " + {$photoSuccessCount} bukti gambar" : ''),
                    'created_at' => now(),
                ]);
            } else {
                $failures[] = $label.': '.($result['error'] ?? 'pengiriman belum dikonfirmasi');
            }
        }

        if ($failures === [] && $alert->status === 'new' && $request->user()->hasPrivilege('alerts.acknowledge')) {
            $alert->update(['status' => 'acknowledged']);
        }
        $message = $delivered === [] ? '' : 'Notifikasi terkirim ke '.count($delivered).' tujuan: '.implode(', ', $delivered).'.';
        if ($failures !== []) {
            $message .= ' Gagal: '.implode('; ', $failures).'.';
        }
        if ($photoFailures !== []) {
            $message .= ' Evidence gagal: '.implode('; ', $photoFailures).'.';
        }
        if ($formatFallback) {
            $message .= ' Format HTML ditolak Telegram; pesan terkirim sebagai teks biasa.';
        }

        return back()->with($failures === [] && $photoFailures === [] ? 'success' : 'error', trim($message));
    }

    /**
     * Get templates and routed destination labels for an alert (AJAX)
     */
    public function template(Alert $alert)
    {
        $templates = NotificationTemplate::allActive()->map(fn($t) => [
            'id'       => $t->id,
            'name'     => $t->name,
            'icon'     => $t->icon,
            'category' => $t->category,
            'body'     => $t->render($alert),
        ]);

        $defaultBody = $templates->first()['body'] ?? TelegramService::buildTemplate($alert->toArray());

        return response()->json([
            'templates'       => $templates,
            'destinations'    => array_column(Configuration::telegramDestinationsForAgent($alert->agent_name), 'label'),
            'default_message' => $defaultBody,
        ]);
    }

    /**
     * Get ALL notification templates list (for Settings management) — Admin only
     */
    public function templatesList()
    {
        return response()->json(NotificationTemplate::orderBy('category')->orderBy('name')->get());
    }

    /**
     * Save a new or update existing template — Admin only
     */
    public function saveTemplate(Request $request)
    {
        $this->adminOnly();

        $request->validate([
            'id'       => 'nullable|exists:notification_templates,id',
            'name'     => 'required|string|max:100',
            'icon'     => 'nullable|string|max:10',
            'category' => 'nullable|string|max:50',
            'body'     => 'required|string',
        ]);

        if ($request->filled('id')) {
            $tpl = NotificationTemplate::findOrFail($request->id);
            $tpl->update($request->only('name', 'icon', 'category', 'body', 'is_active'));
            return response()->json(['success' => true, 'message' => 'Template diperbarui.', 'template' => $tpl]);
        }

        $tpl = NotificationTemplate::create([
            'name'      => $request->name,
            'icon'      => $request->icon      ?? '📢',
            'category'  => $request->category  ?? 'General',
            'body'      => $request->body,
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'message' => 'Template disimpan.', 'template' => $tpl]);
    }

    /**
     * Delete a template — Admin only
     */
    public function deleteTemplate(NotificationTemplate $template)
    {
        $this->adminOnly();
        $template->delete();
        return response()->json(['success' => true]);
    }
}
