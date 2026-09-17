@extends('layouts.app')

@section('title', 'Log Aktivitas')
@section('page-context', 'History')
@section('page-title', 'Log Aktivitas')
@section('page-subtitle', 'Semua tindakan triage oleh analist')

@section('content')

<div class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <form method="GET" action="{{ route('history.activity') }}">
            <div class="filter-bar">
                <div>
                    <label class="form-label">Aksi</label>
                    <select name="action" class="form-select" onchange="this.form.submit()">
                        <option value="all" {{ request('action','all') === 'all' ? 'selected':'' }}>Semua Aksi</option>
                        <option value="acknowledge" {{ request('action') === 'acknowledge' ? 'selected':'' }}>Acknowledge</option>
                        <option value="ignore"      {{ request('action') === 'ignore' ? 'selected':'' }}>Ignore</option>
                        <option value="notify"      {{ request('action') === 'notify' ? 'selected':'' }}>Notify</option>
                    </select>
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
                    <a href="{{ route('history.activity') }}" class="btn btn-ghost">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">{{ $triages->total() }} aktivitas tercatat</span>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Alert</th>
                    <th>Analis</th>
                    <th>Aksi</th>
                    <th>Alasan / Catatan</th>
                    <th>Waktu</th>
                </tr>
            </thead>
            <tbody>
                @forelse($triages as $triage)
                <tr>
                    <td>
                        @if($triage->alert)
                        <a href="{{ route('alerts.show', $triage->alert) }}" style="color:var(--accent);">
                            #{{ $triage->alert_id }}
                            <div class="truncate-cell text-muted" style="font-size:0.72rem;">{{ $triage->alert->rule_description }}</div>
                        </a>
                        @else
                        #{{ $triage->alert_id }}
                        @endif
                    </td>
                    <td style="font-weight:600;">{{ $triage->user->name ?? 'Unknown' }}</td>
                    <td>
                        <span class="badge {{ $triage->action === 'ignore' ? 'badge-ignored' : ($triage->action === 'acknowledge' ? 'badge-acknowledged' : 'badge-new') }}">
                            {{ strtoupper($triage->action) }}
                        </span>
                    </td>
                    <td>
                        <div class="truncate-cell" style="max-width:250px;" title="{{ $triage->reason }}">
                            {{ $triage->reason ?? '-' }}
                        </div>
                    </td>
                    <td style="font-size:0.72rem; white-space:nowrap;">
                        {{ $triage->created_at?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-' }}
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" style="text-align:center; padding:3rem; color:var(--text-muted);">Belum ada aktivitas tercatat</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($triages->hasPages())
    <div style="padding:1rem; border-top:1px solid var(--border);">{{ $triages->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
