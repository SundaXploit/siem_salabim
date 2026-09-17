@extends('layouts.app')

@section('title', 'Riwayat Alert')
@section('page-context', 'History')
@section('page-title', 'Riwayat Alert')
@section('page-subtitle', 'Semua alert yang pernah masuk')

@section('content')

<div class="card" style="margin-bottom:1rem;">
    <div class="card-body">
        <form method="GET" action="{{ route('history.alerts') }}">
            <div class="filter-bar">
                <div style="flex:1; min-width:200px;">
                    <label class="form-label">Cari event</label>
                    <input type="text" name="search" class="form-input" placeholder="Rule, IP, agent..." value="{{ request('search') }}">
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" {{ request('status','all') === 'all' ? 'selected':'' }}>Semua</option>
                        <option value="new"          {{ request('status') === 'new'          ? 'selected':'' }}>NEW</option>
                        <option value="acknowledged" {{ request('status') === 'acknowledged' ? 'selected':'' }}>ACKNOWLEDGED</option>
                        <option value="ignored"      {{ request('status') === 'ignored'      ? 'selected':'' }}>IGNORED</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Min Level</label>
                    <select name="level" class="form-select" onchange="this.form.submit()">
                        <option value="">Semua</option>
                        <option value="12" {{ request('level') == 12 ? 'selected':'' }}>≥ 12</option>
                        <option value="15" {{ request('level') == 15 ? 'selected':'' }}>≥ 15</option>
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
                    <th>ID</th>
                    <th>Rule Description</th>
                    <th>Source IP</th>
                    <th>Agent</th>
                    <th>Level</th>
                    <th>Status</th>
                    <th>Pertama Terdeteksi</th>
                    <th>Detail</th>
                </tr>
            </thead>
            <tbody>
                @forelse($alerts as $alert)
                <tr class="row-{{ $alert->status }}">
                    <td style="font-family:monospace; color:var(--text-muted);">#{{ $alert->id }}</td>
                    <td><div class="truncate-cell">{{ $alert->rule_description ?? '-' }}</div></td>
                    <td style="font-family:monospace; font-size:0.75rem; color:var(--info);">{{ $alert->src_ip ?? '-' }}</td>
                    <td>{{ $alert->agent_name ?? '-' }}</td>
                    <td><span class="table-level" aria-label="Rule level {{ $alert->rule_level }}">{{ $alert->rule_level }}</span></td>
                    <td><span class="table-status">{{ $alert->status_label }}</span></td>
                    <td style="font-size:0.72rem; white-space:nowrap;">
                        {{ $alert->first_seen_at?->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') ?? '-' }}
                    </td>
                    <td>
                        <a href="{{ route('alerts.show', $alert) }}" class="table-action" title="Lihat detail alert" aria-label="Lihat detail alert"><i data-lucide="eye" aria-hidden="true"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" style="text-align:center; padding:3rem; color:var(--text-muted);">Tidak ada data</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($alerts->hasPages())
    <div style="padding:1rem; border-top:1px solid var(--border);">{{ $alerts->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
