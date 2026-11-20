@extends('layouts.app')
@section('body-class', 'density-default history-page')

@section('title', 'Riwayat Alert')
@section('page-context', 'History')
@section('page-title', 'Riwayat Alert')
@section('page-subtitle', 'Semua alert yang pernah masuk')

@section('content')

<div class="card" style="margin-bottom:var(--spacing-16);">
    <div class="card-body">
        <form method="GET" action="{{ route('history.alerts') }}">
            <div class="filter-bar">
                <div style="flex:1; min-width:200px;">
                    <label class="form-label" for="history-alerts-search">Cari event</label>
                    <input id="history-alerts-search" type="text" name="search" class="form-input" placeholder="Rule, IP, agent..." value="{{ request('search') }}">
                </div>
                <div>
                    <label class="form-label" for="history-alerts-status">Status</label>
                    <select id="history-alerts-status" name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" {{ request('status','all') === 'all' ? 'selected':'' }}>Semua</option>
                        <option value="new"          {{ request('status') === 'new'          ? 'selected':'' }}>NEW</option>
                        <option value="acknowledged" {{ request('status') === 'acknowledged' ? 'selected':'' }}>ACKNOWLEDGED</option>
                        <option value="ignored"      {{ request('status') === 'ignored'      ? 'selected':'' }}>IGNORED</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="history-alerts-level">Min Level</label>
                    <select id="history-alerts-level" name="level" class="form-select" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        <option value="12" {{ request('level') == 12 ? 'selected':'' }}>≥ 12</option>
                        <option value="15" {{ request('level') == 15 ? 'selected':'' }}>≥ 15</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="history-alerts-date_from">Dari ({{ \App\Support\LocalTime::label() }})</label>
                    <input id="history-alerts-date_from" type="date" name="date_from" class="form-input" value="{{ request('date_from') }}" onchange="this.form.submit()">
                </div>
                <div>
                    <label class="form-label" for="history-alerts-date_to">Sampai ({{ \App\Support\LocalTime::label() }})</label>
                    <input id="history-alerts-date_to" type="date" name="date_to" class="form-input" value="{{ request('date_to') }}" onchange="this.form.submit()">
                </div>
                <div class="flex gap-2 items-center">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('history.alerts') }}" class="btn btn-ghost">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title">{{$alerts->total()}} alert ditemukan</span>
    </div>
    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Rule Description</th>
                    <th scope="col">Source IP</th>
                    <th scope="col">Agent</th>
                    <th scope="col">Level</th>
                    <th scope="col">Status</th>
                    <th scope="col">Pertama Terdeteksi</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody>
                @forelse($alerts as $alert)
                <tr class="row-{{ $alert->status }}">
                    <td style="font-family:var(--font-mono); color:var(--text-muted);">#{{ $alert->id }}</td>
                    <td><div class="truncate-cell">{{ $alert->rule_description ?? '-' }}</div></td>
                    <td style="font-family:var(--font-mono); font-size:var(--text-xs); color:var(--info); line-height:var(--leading-xs);">{{ $alert->src_ip ?? '-' }}</td>
                    <td>{{ $alert->agent_name ?? '-' }}</td>
                    <td><span class="table-level {{ $alert->level_badge_class }}" aria-label="Rule level {{ $alert->rule_level }}">{{ $alert->rule_level }}</span></td>
                    <td><span class="table-status {{ $alert->status_badge_class }}">{{ $alert->status_label }}</span></td>
                    <td style="font-size:var(--text-xs); white-space:nowrap; line-height:var(--leading-xs);">
                        {{ $alert->first_seen_at?->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '-' }}
                    </td>
                    <td>
                        <a href="{{ route('alerts.show', $alert) }}" class="table-action" title="Lihat detail alert" aria-label="Lihat detail alert"><i data-lucide="eye" aria-hidden="true"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center; padding:var(--spacing-48); color:var(--text-muted);">Tidak ada data</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($alerts->hasPages())
    <div style="padding:var(--spacing-16); border-top:1px solid var(--border);">{{ $alerts->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
