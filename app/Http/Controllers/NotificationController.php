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
use Illuminate\Support\Facades\Log;

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
            'chat_id'    => 'required|string',
            'message'    => 'required|string|min:5',
            'evidence'   => 'nullable|array',
            'evidence.*' => 'image|max:10240', // max 10MB
        ], [
            'chat_id.required'   => 'Chat ID harus dipilih.',
            'message.required'   => 'Pesan tidak boleh kosong.',
            'evidence.array'     => 'Format evidence tidak valid.',
            'evidence.*.image'   => 'File evidence harus berupa gambar (jpg, png, gif, webp).',
            'evidence.*.max'     => 'Ukuran gambar maksimal 10MB.',
        ]);

        $chatId  = trim((string) $request->chat_id);
        $message = $request->message;

        // ── Step 1: Send text notification ──────────────────────────────────
        $result = $telegram->sendMessage($chatId, $message);

        // ── Step 2: If evidence images attached, send them as separate messages
        $photoSuccessCount = 0;
        $photoFailCount    = 0;
        $photoErrors       = [];

        // Send evidence only after the primary message is confirmed so a
        // failed notification cannot leave orphaned screenshots in the chat.
        if ($result['success'] && $request->hasFile('evidence')) {
            $files = $request->file('evidence');
            foreach ($files as $file) {
                if ($file->isValid()) {
                    // Send directly from PHP temp directory (no need to store securely on web server)
                    $tmpPhotoPath = $file->getRealPath();
                    $filename     = $file->getClientOriginalName();

                    Log::info("[Telegram] Preparing to send evidence (from memory/temp): {$tmpPhotoPath} to Chat ID: {$chatId}");

                    // Send photo
                    $photoResult = $telegram->sendPhoto($chatId, $tmpPhotoPath, $filename);

                    if ($photoResult['success']) {
                        $photoSuccessCount++;
                        Log::info("[Telegram] Successfully sent evidence: {$filename}");
                    } else {
                        $photoFailCount++;
                        $error = $photoResult['error'] ?? 'unknown';
                        $photoErrors[] = "{$filename} ({$error})";
                        Log::error("[Telegram] Failed from Controller side: " . json_encode($photoResult));
                    }

                    // Workaround for Windows file locking: Collect garbage to free resources
                    gc_collect_cycles();
                }
            }
        }

        // ── Log notification ─────────────────────────────────────────────────
        NotificationLog::create([
            'alert_id'        => $alert->id,
            'user_id'         => Auth::id(),
            'chat_id'         => $chatId,
            'message'         => $message . ($photoSuccessCount > 0 ? "\n\n📎 [{$photoSuccessCount} Evidence gambar dikirim terpisah]" : ''),
            'response_status' => $result['status'],
            'sent_at'         => now(),
        ]);

        // ── Log triage action ─────────────────────────────────────────────────
        if ($result['success']) {
            AlertTriage::create([
                'alert_id'   => $alert->id,
                'user_id'    => Auth::id(),
                'action'     => 'notify',
                'reason'     => "Notifikasi dikirim ke {$chatId}" . ($photoSuccessCount > 0 ? " + {$photoSuccessCount} bukti gambar" : ''),
                'created_at' => now(),
            ]);
        }

        // ── Auto-acknowledge ──────────────────────────────────────────────────
        if ($result['success'] && $alert->status === 'new') {
            $alert->update(['status' => 'acknowledged']);
        }

        // ── Response ──────────────────────────────────────────────────────────
        if ($result['success']) {
            $msg = '✅ Notifikasi berhasil dikirim ke Telegram!';
            if ($request->hasFile('evidence')) {
                if ($photoSuccessCount > 0) {
                    $msg .= " 📎 {$photoSuccessCount} Gambar evidence terkirim.";
                }
                if ($photoFailCount > 0) {
                    $msg .= " ⚠️ {$photoFailCount} gambar gagal dikirim: " . implode(', ', $photoErrors);
                }
            }
            return back()->with('success', $msg);
        } else {
            return back()->with('error', '❌ Gagal mengirim notifikasi: ' . ($result['error'] ?? 'Unknown error'));
        }
    }

    /**
     * Get all templates + chat IDs for an alert (AJAX)
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
            'chat_ids'        => Configuration::getTelegramChatIds(),
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
