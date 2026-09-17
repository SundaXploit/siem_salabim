<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Services\AlertTriageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TriageController extends Controller
{
    public function acknowledge(Alert $alert, AlertTriageService $triage)
    {
        $result = $triage->apply([$alert->id], 'acknowledge', Auth::id());
        if ($result['processed'] === 0) {
            return back()->with('error', 'Alert sudah ditangani atau diabaikan, tidak bisa di-acknowledge lagi.');
        }

        return back()->with('success', "Alert #{$alert->id} berhasil di-acknowledge.");
    }

    public function ignore(Request $request, Alert $alert, AlertTriageService $triage)
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ], [
            'reason.required' => 'Alasan harus diisi sebelum mengabaikan alert.',
            'reason.min'      => 'Alasan minimal 5 karakter.',
        ]);

        $result = $triage->apply([$alert->id], 'ignore', Auth::id(), $request->reason);
        if ($result['processed'] === 0) {
            return back()->with('error', 'Alert sudah diabaikan.');
        }

        return back()->with('success', "Alert #{$alert->id} berhasil diabaikan.");
    }

    public function bulk(Request $request, AlertTriageService $triage)
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->isAnalyst()), 403);

        $data = $request->validate([
            'alert_ids' => ['required', 'array', 'min:1', 'max:' . AlertTriageService::MAX_BATCH_SIZE],
            'alert_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'action' => ['required', Rule::in(['acknowledge', 'ignore'])],
            'reason' => [Rule::requiredIf($request->input('action') === 'ignore'), 'nullable', 'string', 'min:5', 'max:1000'],
        ], [
            'alert_ids.required' => 'Pilih setidaknya satu alert.',
            'alert_ids.max' => 'Maksimal 100 alert dalam satu triage massal.',
            'alert_ids.*.distinct' => 'Pilihan alert tidak boleh duplikat.',
            'reason.required' => 'Alasan wajib diisi untuk mengabaikan alert.',
            'reason.min' => 'Alasan minimal 5 karakter.',
            'reason.max' => 'Alasan maksimal 1000 karakter.',
        ]);

        $result = $triage->apply($data['alert_ids'], $data['action'], $user->id, $data['reason'] ?? null);
        $label = $data['action'] === 'acknowledge' ? 'di-ACK' : 'diabaikan';
        $message = "{$result['processed']} alert berhasil {$label}.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} alert dilewati karena statusnya tidak sesuai atau sudah ditangani.";
        }

        return response()->json(['success' => true, 'message' => $message, ...$result]);
    }
}
