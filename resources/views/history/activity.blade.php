@extends('layouts.app')
@section('body-class', 'density-default history-page')
@section('title', 'Log aktivitas')
@section('page-context', 'Administration')
@section('page-title', 'Log aktivitas')
@section('page-subtitle', 'Riwayat akses akun, perubahan profil, dan aktivitas Settings · Khusus admin.')

@section('content')
<div class="card" style="margin-bottom:var(--spacing-16);">
    <div class="card-body">
        <form method="GET" action="{{ route('history.activity') }}">
            <div class="activity-filter-grid">
                <div>
                    <label class="form-label" for="activity-search">Cari pelaku, objek, atau IP</label>
                    <input id="activity-search" type="search" name="search" class="form-input" maxlength="200" value="{{ request('search') }}" placeholder="Cari aktivitas akun atau Settings…">
                </div>
                <div>
                    <label class="form-label" for="activity-action">Aktivitas</label>
                    <select id="activity-action" name="action" class="form-select" onchange="this.form.requestSubmit()">
                        <option value="all" @selected(request('action', 'all') === 'all')>Semua aktivitas</option>
                        @foreach($actions as $value => $label)<option value="{{ $value }}" @selected(request('action') === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div><label class="form-label" for="activity-from">Dari ({{ \App\Support\LocalTime::label() }})</label><input id="activity-from" type="date" name="date_from" class="form-input" value="{{ request('date_from') }}"></div>
                <div><label class="form-label" for="activity-to">Sampai ({{ \App\Support\LocalTime::label() }})</label><input id="activity-to" type="date" name="date_to" class="form-input" value="{{ request('date_to') }}"></div>
                <div class="activity-filter-actions"><button type="submit" class="btn btn-primary">Filter</button><a href="{{ route('history.activity') }}" class="btn btn-ghost">Reset</a></div>
            </div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header"><span class="card-title">{{ $logs->total() }} aktivitas tercatat</span><span class="text-muted text-xs">Terbaru lebih dulu · {{ \App\Support\LocalTime::label() }}</span></div>
    <div style="overflow-x:auto;">
        <table class="data-table" data-activity-log>
            <thead><tr><th scope="col">Pelaku</th><th scope="col">Aktivitas</th><th scope="col">Objek / keterangan</th><th scope="col">Hasil</th><th scope="col">IP</th><th scope="col">Waktu ({{ \App\Support\LocalTime::label() }})</th></tr></thead>
            <tbody>
                @forelse($logs as $log)
                <tr data-activity-action="{{ $log->action }}">
                    <td><strong>{{ $log->actor_name }}</strong><div class="text-muted text-xs">{{ $log->actor_role }}</div></td>
                    <td>{{ $log->action_label }}</td>
                    <td style="min-width:180px; max-width:360px;">
                        <span>{{ $log->subject ?? '-' }}</span>
                        @if(array_key_exists('is_active', $log->context ?? []))<div class="text-muted text-xs">Status aktif: {{ $log->context['is_active'] ? 'Aktif' : 'Nonaktif' }}</div>@endif
                        @if(!empty($log->context['fields']))<div class="text-muted text-xs" style="overflow-wrap:anywhere;">Bidang formulir: {{ implode(', ', $log->context['fields']) }}</div>@endif
                    </td>
                    <td><span class="badge {{ $log->result === 'success' ? 'badge-sent' : 'badge-failed' }}">{{ $log->result === 'success' ? 'BERHASIL' : 'GAGAL' }}</span></td>
                    <td class="font-technical text-xs">{{ $log->ip_address ?? '-' }}</td>
                    <td class="text-xs" style="white-space:nowrap;">{{ $log->created_at?->copy()->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-muted" style="text-align:center; padding:var(--spacing-48);">Belum ada aktivitas yang sesuai filter. Pencatatan dimulai setelah pembaruan fitur ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())<div style="padding:var(--spacing-16); border-top:1px solid var(--border);">{{ $logs->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
