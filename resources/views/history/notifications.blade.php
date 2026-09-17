@extends('layouts.app')

@section('title', 'Log Notifikasi')
@section('page-context', 'History')
@section('page-title', 'Log Notifikasi')
@section('page-subtitle', 'Riwayat notifikasi yang pernah dikirim ke Telegram')

@section('content')

<div class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <form method="GET" action="{{ route('history.notifications') }}">
            <div class="filter-bar">
                <div style="flex:1; min-width:200px;">
                    <label class="form-label">Cari Chat ID atau pesan</label>
                    <input type="text" name="search" class="form-input" placeholder="Chat ID..." value="{{ request('search') }}">
                </div>
                <div>
                    <label class="form-label">Dari</label>
                    <input type="date" name="date_from" class="form-input" value="{{ request('date_from') }}" onchange="this.form.submit()">
                </div>
                <div>
                    <label class="form-label">Sampai</label>
                    <input type="date" name="date_to" class="form-input" value="{{ request('date_to') }}" onchange="this.form.submit()">
                </div>
                <div class="flex gap-2 items-center">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('history.notifications') }}" class="btn btn-ghost">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">{{ $logs->total() }} notifikasi dikirim</span>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Alert ID</th>
                    <th>Dikirim Oleh</th>
                    <th>Chat ID</th>
                    <th>Pesan</th>
                    <th>Status</th>
                    <th>Waktu</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr>
                    <td>
                        @if($log->alert)
                        <a href="{{ route('alerts.show', $log->alert) }}" style="color:var(--accent);">#{{ $log->alert_id }}</a>
                        @else
                        #{{ $log->alert_id }}
                        @endif
                    </td>
                    <td>{{ $log->user->name ?? 'Unknown' }}</td>
                    <td style="font-family:monospace; font-size:0.75rem; color:var(--info);">{{ $log->chat_id }}</td>
                    <td>
                        <div class="truncate-cell" title="{{ $log->message }}" style="max-width:300px;">{{ $log->message }}</div>
                    </td>
                    <td>
                        <span class="badge {{ $log->response_status === 200 ? 'badge-acknowledged' : 'badge-ignored' }}">
                            {{ $log->response_status === 200 ? 'SENT' : 'FAILED (' . $log->response_status . ')' }}
                        </span>
                    </td>
                    <td style="font-size:0.72rem; white-space:nowrap;">
                        {{ $log->sent_at?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-' }}
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" style="text-align:center; padding:3rem; color:var(--text-muted);">Belum ada notifikasi dikirim</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <div style="padding:1rem; border-top:1px solid var(--border);">{{ $logs->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
