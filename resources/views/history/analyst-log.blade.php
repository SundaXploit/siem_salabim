@extends('layouts.app')
@section('body-class', 'density-default history-page')
@section('title', 'Log analis')
@section('page-context', 'History')
@section('page-title', 'Log analis')
@section('page-subtitle', 'Riwayat notifikasi Telegram manual dan otomatis, ACK, dan ignore.')

@section('content')
<div class="card" style="margin-bottom:var(--spacing-16);">
    <div class="card-body">
        <form method="GET" action="{{ route('analyst-log.index') }}">
            <div class="filter-bar">
                <div style="flex:1; min-width:200px;">
                    <label class="form-label" for="analyst-log-search">Cari analis, agent, Chat ID, atau catatan</label>
                    <input id="analyst-log-search" type="search" name="search" class="form-input" maxlength="200" value="{{ request('search') }}" placeholder="Cari riwayat analis…">
                </div>
                <div>
                    <label class="form-label" for="analyst-log-action">Aksi</label>
                    <select id="analyst-log-action" name="action" class="form-select" onchange="this.form.requestSubmit()">
                        @foreach(['all' => 'Semua aksi', 'notify' => 'Notifikasi', 'acknowledge' => 'ACK', 'ignore' => 'Ignore'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('action', 'all') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div><label class="form-label" for="analyst-log-from">Dari ({{ \App\Support\LocalTime::label() }})</label><input id="analyst-log-from" type="date" name="date_from" class="form-input" value="{{ request('date_from') }}"></div>
                <div><label class="form-label" for="analyst-log-to">Sampai ({{ \App\Support\LocalTime::label() }})</label><input id="analyst-log-to" type="date" name="date_to" class="form-input" value="{{ request('date_to') }}"></div>
                <div class="flex gap-2 items-center"><button type="submit" class="btn btn-primary">Filter</button><a href="{{ route('analyst-log.index') }}" class="btn btn-ghost">Reset</a></div>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header"><span class="card-title">{{ $logs->total() }} aktivitas analis</span><span class="text-muted text-xs">Terbaru lebih dulu · {{ \App\Support\LocalTime::label() }}</span></div>
    <div style="overflow-x:auto;">
        <table class="data-table" data-analyst-log>
            <thead><tr><th scope="col">Alert</th><th scope="col">Analis</th><th scope="col">Aksi</th><th scope="col">Tujuan / hasil</th><th scope="col">Pesan / catatan</th><th scope="col">Waktu ({{ \App\Support\LocalTime::label() }})</th></tr></thead>
            <tbody>
                @forelse($logs as $log)
                <tr data-log-action="{{ $log->action }}">
                    <td>
                        @if($log->existing_alert_id)<a href="{{ route('alerts.show', $log->alert_id) }}" style="color:var(--accent);">#{{ $log->alert_id }}</a>@else #{{ $log->alert_id }} @endif
                        <div class="truncate-cell text-muted text-xs" style="max-width:220px;" title="{{ $log->rule_description }}">{{ $log->rule_description }}</div>
                        <div class="text-muted text-xs">{{ $log->agent_name }}</div>
                    </td>
                    <td>{{ $log->analyst_name ?? 'Akun tidak tersedia' }}</td>
                    <td><span class="badge {{ $log->action === 'ignore' ? 'badge-ignored' : ($log->action === 'acknowledge' ? 'badge-acknowledged' : 'badge-new') }}">{{ ['notify' => 'NOTIFIKASI', 'acknowledge' => 'ACK', 'ignore' => 'IGNORE'][$log->action] }}</span></td>
                    <td>
                        @if($log->action === 'notify')
                        <div class="font-technical text-xs">{{ $log->chat_id }}</div>
                        @include('partials.notification-result', ['state' => $log->delivery_state, 'status' => $log->response_status])
                        @else<span class="text-muted">-</span>@endif
                    </td>
                    <td style="min-width:200px; max-width:380px;">
                        @if(mb_strlen($log->note ?? '') > 140)
                        <details class="history-log-note"><summary>{{ \Illuminate\Support\Str::limit($log->note, 140) }}</summary><p>{{ $log->note }}</p></details>
                        @else <span>{{ $log->note ?: '-' }}</span> @endif
                    </td>
                    <td class="text-xs" style="white-space:nowrap;">{{ $log->occurred_at ? \Carbon\Carbon::parse($log->occurred_at, config('app.timezone'))->format('d/m/Y H:i:s') : '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-muted" style="text-align:center; padding:var(--spacing-48);">Belum ada riwayat analis yang sesuai filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())<div style="padding:var(--spacing-16); border-top:1px solid var(--border);">{{ $logs->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
