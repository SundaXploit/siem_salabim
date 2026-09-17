<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertTriage;
use App\Models\NotificationLog;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function alerts(Request $request)
    {
        $query = Alert::query();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('level')) {
            $query->where('rule_level', '>=', (int) $request->level);
        }

        if ($request->filled('date_from')) {
            $query->where('first_seen_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('first_seen_at', '<=', $request->date_to . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('rule_description', 'like', "%{$search}%")
                  ->orWhere('src_ip', 'like', "%{$search}%")
                  ->orWhere('agent_name', 'like', "%{$search}%")
                  ->orWhere('rule_id', 'like', "%{$search}%");
            });
        }

        $alerts = $query->orderBy('first_seen_at', 'desc')->paginate(25)->withQueryString();

        return view('history.alerts', compact('alerts'));
    }

    public function notifications(Request $request)
    {
        $query = NotificationLog::with(['alert', 'user']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('chat_id', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->where('sent_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('sent_at', '<=', $request->date_to . ' 23:59:59');
        }

        $logs = $query->orderBy('sent_at', 'desc')->paginate(25)->withQueryString();

        return view('history.notifications', compact('logs'));
    }

    public function activity(Request $request)
    {
        $query = AlertTriage::with(['alert', 'user']);

        if ($request->filled('action') && $request->action !== 'all') {
            $query->where('action', $request->action);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $triages = $query->orderBy('created_at', 'desc')->paginate(25)->withQueryString();

        return view('history.activity', compact('triages'));
    }
}
