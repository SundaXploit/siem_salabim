@extends('layouts.app')

@section('title', 'Kinerja Tim SOC')
@section('page-context', 'Kinerja Operasi')
@section('page-title', 'Kinerja Tim SOC')
@section('page-subtitle', 'Ringkasan aktivitas triage, filter, dan notifikasi')

@push('styles')
<style media="not all" data-legacy-leaderboard="true">
/* ─── Leaderboard CSS Variables ─── */
:root, html.dark {
    --lb-gold:   #f59e0b;
    --lb-silver: #9ca3af;
    --lb-bronze: #d97706;
    --lb-notify: #6366f1;
    --lb-ignore: #10b981;
    --lb-ack:    #06b6d4;
    --lb-gold-glow:   rgba(245,158,11,0.3);
    --lb-silver-glow: rgba(156,163,175,0.2);
    --lb-bronze-glow: rgba(217,119,6,0.25);
}
html.light {
    --lb-gold:   #d97706;
    --lb-silver: #6b7280;
    --lb-bronze: #b45309;
    --lb-notify: #4f46e5;
    --lb-ignore: #059669;
    --lb-ack:    #0284c7;
    --lb-gold-glow:   rgba(217,119,6,0.2);
    --lb-silver-glow: rgba(107,114,128,0.15);
    --lb-bronze-glow: rgba(180,83,9,0.15);
}

/* ─── Filter Bar ─── */
.lb-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0.875rem 1.25rem;
}
.period-pills { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.period-pill {
    padding: 0.35rem 0.9rem;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-secondary);
    font-family: 'Plus Jakarta Sans', sans-serif;
    transition: all 0.2s;
    white-space: nowrap;
}
.period-pill:hover { background: var(--accent-glow); color: var(--accent); border-color: var(--accent); }
.period-pill.active { background: var(--accent); color: #fff; border-color: var(--accent); }

.custom-range-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    width: 100%;
    padding-top: 0.5rem;
    border-top: 1px solid var(--border);
    margin-top: 0.25rem;
}
.custom-range-row input[type="date"] {
    background: var(--bg-secondary);
    border: 1px solid var(--border);
    color: var(--text-primary);
    border-radius: 8px;
    padding: 0.4rem 0.75rem;
    font-size: 0.82rem;
    font-family: 'Plus Jakarta Sans', sans-serif;
    outline: none;
    transition: border-color 0.2s;
    width: 150px;
}
.custom-range-row input[type="date"]:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-glow); }

.lb-period-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0 0.25rem;
}
.lb-period-label span { font-weight: 600; color: var(--text-primary); }

/* ─── Loading ─── */
.lb-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 5rem 2rem;
    gap: 1rem;
    color: var(--text-secondary);
    font-size: 0.9rem;
}
.lb-spinner {
    width: 42px; height: 42px;
    border: 3px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: lb-spin 0.7s linear infinite;
}
@keyframes lb-spin { to { transform: rotate(360deg); } }

/* ─── Section Titles ─── */
.lb-section-title {
    font-size: 0.93rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* ─── Podium card header ─── */
.podium-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--border);
}
.podium-tabs { display: flex; gap: 0.35rem; flex-wrap: wrap; }
.podium-tab {
    padding: 0.3rem 0.75rem;
    border-radius: 7px;
    font-size: 0.76rem;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: transparent;
    color: var(--text-secondary);
    font-family: 'Plus Jakarta Sans', sans-serif;
    transition: all 0.2s;
}
.podium-tab:hover { background: var(--accent-glow); color: var(--accent); border-color: var(--accent); }
.podium-tab.active { background: var(--accent-glow); color: var(--accent); border-color: var(--accent); font-weight: 700; }

/* ─── Podium Stage ─── */
.podium-stage {
    display: flex;
    align-items: flex-end;
    justify-content: center;
    gap: 1.25rem;
    padding: 2rem 2rem 0;
}
.podium-slot {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
    flex: 1;
    max-width: 190px;
    animation: podiumSlideUp 0.4s ease both;
}
.podium-slot.rank-2 { animation-delay: 0.1s; }
.podium-slot.rank-3 { animation-delay: 0.2s; }
@keyframes podiumSlideUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}

.podium-crown { font-size: 1.6rem; animation: lb-bounce 2s ease-in-out infinite; line-height: 1; }
@keyframes lb-bounce {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-7px); }
}

.podium-avatar {
    width: 58px; height: 58px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    font-weight: 800;
    color: #fff;
    position: relative;
}
.podium-slot.rank-1 .podium-avatar {
    width: 72px; height: 72px;
    font-size: 1.6rem;
    background: linear-gradient(135deg, var(--accent), var(--accent-v));
    box-shadow: 0 0 28px var(--accent-glow);
    animation: avatarPulse 3s ease-in-out infinite;
}
@keyframes avatarPulse {
    0%, 100% { box-shadow: 0 0 22px var(--accent-glow); transform: scale(1); }
    50%       { box-shadow: 0 0 44px rgba(99,102,241,0.7); transform: scale(1.04); }
}
.podium-slot.rank-2 .podium-avatar {
    background: linear-gradient(135deg, #6b7280, #9ca3af);
    box-shadow: 0 0 14px var(--lb-silver-glow);
}
.podium-slot.rank-3 .podium-avatar {
    background: linear-gradient(135deg, #92400e, #d97706);
    box-shadow: 0 0 14px var(--lb-bronze-glow);
}

.podium-name {
    font-size: 0.85rem;
    font-weight: 700;
    text-align: center;
    color: var(--text-primary);
    line-height: 1.3;
}
.podium-score {
    font-size: 1.05rem;
    font-weight: 800;
    text-align: center;
}
.podium-slot.rank-1 .podium-score { color: var(--accent); }
.podium-slot.rank-2 .podium-score { color: var(--lb-silver); }
.podium-slot.rank-3 .podium-score { color: var(--lb-bronze); }
.podium-score-unit { font-size: 0.7rem; font-weight: 500; opacity: 0.8; }

.podium-badges-row {
    display: flex;
    gap: 0.2rem;
    flex-wrap: wrap;
    justify-content: center;
    min-height: 1.5rem;
}
.podium-badge-emoji {
    font-size: 1rem;
    cursor: help;
    transition: transform 0.2s;
    line-height: 1;
}
.podium-badge-emoji:hover { transform: scale(1.35); }

/* Podium blocks (the colored bases) */
.podium-block {
    width: 100%;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    font-size: 0.9rem;
    font-weight: 700;
    color: rgba(255,255,255,0.95);
    border-radius: 10px 10px 0 0;
    padding-top: 0.75rem;
    margin-top: 0.5rem;
}
.podium-slot.rank-1 .podium-block {
    height: 120px;
    background: linear-gradient(175deg, var(--lb-gold), #fbbf24);
    box-shadow: 0 -4px 20px var(--lb-gold-glow);
}
.podium-slot.rank-2 .podium-block {
    height: 90px;
    background: linear-gradient(175deg, #4b5563, var(--lb-silver));
    box-shadow: 0 -4px 12px var(--lb-silver-glow);
}
.podium-slot.rank-3 .podium-block {
    height: 70px;
    background: linear-gradient(175deg, #78350f, var(--lb-bronze));
    box-shadow: 0 -4px 12px var(--lb-bronze-glow);
}

/* ─── Empty State ─── */
.lb-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 2rem;
    gap: 0.75rem;
    color: var(--text-secondary);
    text-align: center;
}
.lb-empty-icon { font-size: 3rem; }
.lb-empty-title { font-size: 0.95rem; font-weight: 600; color: var(--text-primary); }
.lb-empty-sub { font-size: 0.82rem; opacity: 0.75; }

/* ─── Charts Grid ─── */
.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
.chart-wrapper { position: relative; min-height: 150px; }

/* ─── Full Analyst Table ─── */
.lb-table th { font-size: 0.7rem; font-weight: 700; }
.lb-table td { vertical-align: middle; }
.analyst-cell { display: flex; align-items: center; gap: 0.75rem; }
.table-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent-v));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
.analyst-name-text { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); }
.analyst-role-text { font-size: 0.68rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }
.you-tag {
    background: var(--accent-glow);
    color: var(--accent);
    border: 1px solid var(--accent);
    border-radius: 10px;
    font-size: 0.62rem;
    font-weight: 700;
    padding: 0.1rem 0.4rem;
    white-space: nowrap;
}
.rank-medal { font-size: 1.2rem; line-height: 1; }
.rank-num { font-size: 0.82rem; font-weight: 700; color: var(--text-secondary); }
.stat-notify { color: var(--lb-notify); font-weight: 700; font-size: 0.9rem; }
.stat-ignore { color: var(--lb-ignore); font-weight: 700; font-size: 0.9rem; }
.stat-ack    { color: var(--lb-ack);    font-weight: 700; font-size: 0.9rem; }
.stat-total  { color: var(--text-primary); font-weight: 800; font-size: 1rem; }

/* Badge chips */
.badge-chips { display: flex; gap: 0.3rem; flex-wrap: wrap; min-width: 120px; }
.badge-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    padding: 0.18rem 0.5rem;
    border-radius: 20px;
    font-size: 0.62rem;
    font-weight: 700;
    white-space: nowrap;
    cursor: help;
    transition: transform 0.15s;
}
.badge-chip:hover { transform: translateY(-1px); }
.badge-chip.most_active   { background: rgba(239,68,68,0.12); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
.badge-chip.top_notifier  { background: rgba(99,102,241,0.12); color: var(--lb-notify); border: 1px solid rgba(99,102,241,0.3); }
.badge-chip.filter_king   { background: rgba(16,185,129,0.12); color: var(--lb-ignore); border: 1px solid rgba(16,185,129,0.3); }
.badge-chip.triage_master { background: rgba(245,158,11,0.12); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
.badge-chip.all_rounder   { background: rgba(139,92,246,0.12); color: #8b5cf6; border: 1px solid rgba(139,92,246,0.3); }

/* My row highlight */
.my-row td { background: rgba(99,102,241,0.06) !important; }
.my-row td:first-child { border-left: 3px solid var(--accent); }

/* ─── Confetti star decoration ─── */
.star-deco {
    position: absolute;
    font-size: 0.8rem;
    opacity: 0.4;
    animation: starFloat 4s ease-in-out infinite;
    pointer-events: none;
    user-select: none;
}
@keyframes starFloat {
    0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.4; }
    50% { transform: translateY(-10px) rotate(15deg); opacity: 0.7; }
}

/* ─── Responsive ─── */
@media (max-width: 900px) {
    .charts-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .podium-stage { gap: 0.5rem; padding: 1.25rem 1rem 0; }
    .podium-slot { max-width: 110px; }
    .podium-slot.rank-1 .podium-avatar { width: 54px; height: 54px; font-size: 1.2rem; }
    .podium-name { font-size: 0.75rem; }
    .podium-score { font-size: 0.9rem; }
    .podium-tab { font-size: 0.7rem; padding: 0.25rem 0.55rem; }
    .badge-chip .badge-label { display: none; }
    .badge-chip { padding: 0.18rem 0.3rem; }
    .lb-filter-bar { gap: 0.5rem; }
    .custom-range-row input[type="date"] { width: 130px; }
}
</style>
@endpush

@push('styles')
<style media="not all" data-legacy-leaderboard-override="true">
/* Enterprise leaderboard override: scorecard, not a decorative podium. */
.lb-filter-bar {
    gap: var(--space-3);
    margin-bottom: var(--space-4);
    padding: var(--space-3) var(--space-4);
    background: var(--color-surface-1);
    border: 1px solid var(--color-border-default);
    border-radius: var(--radius-md);
}
.period-pills, .podium-tabs { gap: var(--space-1); }
.period-pill, .podium-tab {
    min-height: 2rem;
    padding: 0.25rem 0.6rem;
    color: var(--color-text-secondary);
    background: transparent;
    border: 1px solid var(--color-border-default);
    border-radius: var(--radius-sm);
    font-family: var(--font-sans);
    font-size: 0.75rem;
    font-weight: 600;
    transition: background-color 140ms ease, border-color 140ms ease, color 140ms ease;
}
.period-pill:hover, .podium-tab:hover { color: var(--color-accent); background: var(--color-accent-soft); border-color: var(--color-accent); }
.period-pill.active, .podium-tab.active { color: var(--color-accent); background: var(--color-accent-soft); border-color: var(--color-accent); box-shadow: none; }
.custom-range-row { gap: var(--space-2); padding-top: var(--space-3); margin-top: var(--space-2); border-top-color: var(--color-border-default); }
.custom-range-row input[type='date'] { min-height: 2.25rem; color: var(--color-text-primary); background: var(--color-surface-2); border-color: var(--color-border-default); border-radius: var(--radius-sm); font-family: var(--font-sans); font-size: var(--text-sm); }
.lb-period-label { margin-bottom: var(--space-3); padding: 0; color: var(--color-text-secondary); font-size: var(--text-sm); }
.lb-period-label::before { width: 2px; height: 0.75rem; background: var(--color-accent); content: ''; }
.lb-loading { min-height: 12rem; padding: var(--space-6); font-size: var(--text-sm); }
.lb-spinner { width: 1.5rem; height: 1.5rem; border-width: 2px; border-color: var(--color-border-default); border-top-color: var(--color-accent); }
.podium-header { min-height: 3rem; padding: 0 var(--space-4); background: var(--color-surface-2); border-bottom-color: var(--color-border-default); }
.lb-section-title { color: var(--color-text-primary); font-size: 0.8125rem; font-weight: 650; }
.star-deco, .podium-crown, .podium-badge-emoji { display: none !important; }
.podium-stage { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0; align-items: stretch; padding: 0; }
.podium-slot { max-width: none; gap: var(--space-1); padding: var(--space-4); border-right: 1px solid var(--color-border-default); animation: none; }
.podium-slot:last-child { border-right: 0; }
.podium-slot.rank-1 { order: -1; background: var(--color-accent-soft); }
.podium-avatar, .podium-slot.rank-1 .podium-avatar, .podium-slot.rank-2 .podium-avatar, .podium-slot.rank-3 .podium-avatar {
    width: 2rem; height: 2rem; color: var(--color-accent); background: transparent; border: 1px solid currentColor; border-radius: 50%; box-shadow: none; font-size: var(--text-sm); animation: none;
}
.podium-name { color: var(--color-text-primary); font-size: var(--text-sm); font-weight: 600; }
.podium-score, .podium-slot.rank-1 .podium-score, .podium-slot.rank-2 .podium-score, .podium-slot.rank-3 .podium-score { color: var(--color-text-primary); font-family: var(--font-mono); font-size: 1.25rem; font-variant-numeric: tabular-nums; font-weight: 600; }
.podium-score-unit { color: var(--color-text-muted); font-family: var(--font-sans); font-size: 0.6875rem; }
.podium-block, .podium-slot.rank-1 .podium-block, .podium-slot.rank-2 .podium-block, .podium-slot.rank-3 .podium-block { width: auto; height: auto; margin-top: var(--space-2); padding: 0; color: var(--color-text-muted); background: transparent; border: 0; border-radius: 0; box-shadow: none; font-family: var(--font-mono); font-size: var(--text-sm); font-weight: 500; }
.charts-grid { gap: var(--space-4); }
.chart-wrapper { min-height: 12rem; }
.lb-empty { padding: var(--space-6); }
.lb-empty-icon { display: grid; width: 1.75rem; height: 1.75rem; place-items: center; color: var(--color-text-muted); }
.lb-empty-icon svg { width: 1.35rem; height: 1.35rem; }
.table-avatar { width: 1.85rem; height: 1.85rem; color: var(--color-accent); background: var(--color-accent-soft); border: 1px solid color-mix(in srgb, var(--color-accent) 28%, transparent); box-shadow: none; font-size: var(--text-sm); }
.analyst-cell { gap: var(--space-2); }
.analyst-name-text { color: var(--color-text-primary); font-size: var(--text-sm); }
.analyst-role-text { color: var(--color-text-muted); font-size: 0.625rem; }
.you-tag { color: var(--color-accent); background: var(--color-accent-soft); border-color: color-mix(in srgb, var(--color-accent) 28%, transparent); border-radius: var(--radius-xs); }
.rank-medal { color: var(--color-text-secondary); font-family: var(--font-mono); font-size: var(--text-sm); }
.badge-chips { min-width: 0; }
.badge-chip { min-height: 1.35rem; padding: 0.1rem 0.4rem; border-radius: var(--radius-xs); font-size: 0.65rem; transform: none !important; }
.badge-chip > span:first-child { display: none; }
.my-row td { background: var(--color-accent-soft) !important; }
.my-row td:first-child { border-left-color: var(--color-accent); }
@media (max-width: 640px) {
    .podium-stage { grid-template-columns: 1fr; }
    .podium-slot, .podium-slot:last-child { align-items: flex-start; border-right: 0; border-bottom: 1px solid var(--color-border-default); }
    .podium-slot:last-child { border-bottom: 0; }
}
</style>
@endpush

@section('content')
{{-- Pre-loaded data: safer than inline JS embedding --}}
<script id="lb-initial-data" type="application/json">@json($initialData)</script>

<div class="leaderboard-page" x-data="leaderboard({{ auth()->id() }})" x-init="init()">

    {{-- ─── Filter Bar ─── --}}
    <div class="lb-filter-bar">
        <div class="period-pills">
            <button type="button" class="period-pill" :class="{ active: period === 'today' }"
                @click="setPeriod('today')">Hari Ini</button>
            <button type="button" class="period-pill" :class="{ active: period === 'week' }"
                @click="setPeriod('week')">Minggu Ini</button>
            <button type="button" class="period-pill" :class="{ active: period === 'month' }"
                @click="setPeriod('month')">Bulan Ini</button>
            <button type="button" class="period-pill" :class="{ active: period === 'year' }"
                @click="setPeriod('year')">Tahun Ini</button>
            <button type="button" class="period-pill" :class="{ active: period === 'custom' }"
                @click="period = 'custom'">Rentang</button>
        </div>

        <button type="button" class="btn btn-ghost btn-icon ml-auto"
            @click="fetchData()"
            :disabled="loading"
            style="flex-shrink:0;" title="Muat ulang data" aria-label="Muat ulang data">
            <span x-show="!loading"><i data-lucide="refresh-cw" aria-hidden="true"></i></span>
            <span x-show="loading" style="display:none;"><i data-lucide="clock-3" aria-hidden="true"></i><span class="sr-only">Memuat</span></span>
        </button>

        {{-- Custom range row --}}
        <div class="custom-range-row" x-show="period === 'custom'" x-cloak>
            <label style="font-size:0.78rem; font-weight:600; color:var(--text-secondary);">Dari</label>
            <input type="date" x-model="dateFrom" id="lb-date-from">
            <label style="font-size:0.78rem; font-weight:600; color:var(--text-secondary);">s/d</label>
            <input type="date" x-model="dateTo" id="lb-date-to">
            <button type="button" class="btn btn-primary btn-sm" @click="fetchData()">Terapkan</button>
        </div>
    </div>

    {{-- Period label --}}
    <div class="lb-period-label" x-show="data">
        Periode aktif <span x-text="data?.period_label ?? '...'"></span>
    </div>

    <div class="lb-inline-error" x-show="error" x-cloak role="alert">
        <i data-lucide="circle-alert" aria-hidden="true"></i>
        <span x-text="error"></span>
    </div>

    {{-- ─── Loading State ─── --}}
    <div class="lb-loading" x-show="loading">
        <div class="lb-spinner"></div>
        <div>Mengambil data prestasi analyst...</div>
    </div>

    {{-- ─── Main content ─── --}}
    <div x-show="ready && !loading">

        <section class="lb-summary-grid" aria-label="Ringkasan aktivitas tim SOC">
            <article class="lb-summary-card">
                <span class="lb-summary-icon"><i data-lucide="activity" aria-hidden="true"></i></span>
                <span class="lb-summary-label">Total aksi</span>
                <strong class="lb-summary-value" x-text="teamTotals.total"></strong>
                <span class="lb-summary-note">Seluruh aktivitas tercatat</span>
            </article>
            <article class="lb-summary-card">
                <span class="lb-summary-icon"><i data-lucide="users" aria-hidden="true"></i></span>
                <span class="lb-summary-label">Operator aktif</span>
                <strong class="lb-summary-value" x-text="teamTotals.active"></strong>
                <span class="lb-summary-note" x-text="'dari ' + (data?.by_total?.length ?? 0) + ' operator' "></span>
            </article>
            <article class="lb-summary-card">
                <span class="lb-summary-icon"><i data-lucide="check-check" aria-hidden="true"></i></span>
                <span class="lb-summary-label">Triage</span>
                <strong class="lb-summary-value" x-text="teamTotals.triage"></strong>
                <span class="lb-summary-note">Acknowledge dan filter</span>
            </article>
            <article class="lb-summary-card">
                <span class="lb-summary-icon"><i data-lucide="send" aria-hidden="true"></i></span>
                <span class="lb-summary-label">Notifikasi</span>
                <strong class="lb-summary-value" x-text="teamTotals.notify"></strong>
                <span class="lb-summary-note">Notifikasi tercatat</span>
            </article>
        </section>

        {{-- ═══════════════════════════════════════════════════════════
             PODIUM SECTION
        ═══════════════════════════════════════════════════════════ --}}
        <div class="lb-operation-grid mb-4" x-show="hasActivity" x-cloak>
            <section class="card lb-contributors-card">
            <div class="lb-panel-header">
                <div>
                    <div class="lb-section-title">Kontributor aktif</div>
                    <div class="lb-panel-subtitle" x-text="'Diurutkan berdasarkan ' + currentMetricLabel().toLowerCase()"></div>
                </div>
                <div class="podium-tabs">
                    <button type="button" class="podium-tab" :class="{ active: activeTab === 'total' }" :aria-pressed="activeTab === 'total'"
                        @click="activeTab = 'total'">Total</button>
                    <button type="button" class="podium-tab" :class="{ active: activeTab === 'notify' }" :aria-pressed="activeTab === 'notify'"
                        @click="activeTab = 'notify'">Notifikasi</button>
                    <button type="button" class="podium-tab" :class="{ active: activeTab === 'ignore' }" :aria-pressed="activeTab === 'ignore'"
                        @click="activeTab = 'ignore'">Filter</button>
                    <button type="button" class="podium-tab" :class="{ active: activeTab === 'ack' }" :aria-pressed="activeTab === 'ack'"
                        @click="activeTab = 'ack'">Acknowledge</button>
                </div>
            </div>

            <div class="lb-rank-list" x-show="currentRanking.length > 0" x-cloak>
                <template x-for="(analyst, index) in currentRanking" :key="analyst.user_id">
                    <article class="lb-rank-row" :class="{ 'is-leader': index === 0 }">
                        <span class="lb-rank-number" x-text="String(index + 1).padStart(2, '0')"></span>
                        <span class="lb-rank-avatar" x-text="analyst.initials"></span>
                        <div class="lb-rank-identity">
                            <strong x-text="analyst.name"></strong>
                            <span x-text="analyst.role"></span>
                            <span class="lb-rank-progress"><span :style="'width:' + rankPercent(analyst) + '%' "></span></span>
                        </div>
                        <div class="lb-rank-score">
                            <strong x-text="currentValue(analyst)"></strong>
                            <span x-text="currentUnit()"></span>
                        </div>
                    </article>
                </template>
            </div>

            <div class="lb-selected-empty" x-show="hasActivity && currentRanking.length === 0" x-cloak>
                <i data-lucide="activity" aria-hidden="true"></i>
                <div>
                    <strong x-text="'Belum ada ' + currentMetricLabel().toLowerCase()"></strong>
                    <span>Pilih metrik lain atau lanjutkan penanganan alert.</span>
                </div>
            </div>

            {{-- Podium Stage --}}
            <template x-if="false">
                <div class="podium-stage">

                    {{-- Rank 2 --}}
                    <div class="podium-slot rank-2" x-show="currentRanking[1]">
                        <div class="podium-avatar" x-text="currentRanking[1]?.initials ?? '?'"></div>
                        <div class="podium-name" x-text="currentRanking[1]?.name ?? '—'"></div>
                        <div class="podium-score">
                            <span x-text="currentValue(currentRanking[1])"></span>
                            <span class="podium-score-unit" x-text="currentUnit()"></span>
                        </div>
                        <div class="podium-badges-row">
                            <template x-for="b in (currentRanking[1]?.badges ?? [])" :key="b.key">
                                <span class="podium-badge-emoji" :title="b.desc" x-text="b.icon"></span>
                            </template>
                        </div>
                        <div class="podium-block">#2</div>
                    </div>

                    {{-- Rank 1 --}}
                    <div class="podium-slot rank-1" x-show="currentRanking[0]">
                        <div class="podium-avatar" x-text="currentRanking[0]?.initials ?? '?'"></div>
                        <div class="podium-name" x-text="currentRanking[0]?.name ?? '—'"></div>
                        <div class="podium-score">
                            <span x-text="currentValue(currentRanking[0])"></span>
                            <span class="podium-score-unit" x-text="currentUnit()"></span>
                        </div>
                        <div class="podium-badges-row">
                            <template x-for="b in (currentRanking[0]?.badges ?? [])" :key="b.key">
                                <span class="podium-badge-emoji" :title="b.desc" x-text="b.icon"></span>
                            </template>
                        </div>
                        <div class="podium-block">#1</div>
                    </div>

                    {{-- Rank 3 --}}
                    <div class="podium-slot rank-3" x-show="currentRanking[2]">
                        <div class="podium-avatar" x-text="currentRanking[2]?.initials ?? '?'"></div>
                        <div class="podium-name" x-text="currentRanking[2]?.name ?? '—'"></div>
                        <div class="podium-score">
                            <span x-text="currentValue(currentRanking[2])"></span>
                            <span class="podium-score-unit" x-text="currentUnit()"></span>
                        </div>
                        <div class="podium-badges-row">
                            <template x-for="b in (currentRanking[2]?.badges ?? [])" :key="b.key">
                                <span class="podium-badge-emoji" :title="b.desc" x-text="b.icon"></span>
                            </template>
                        </div>
                        <div class="podium-block">#3</div>
                    </div>
                </div>
            </template>

            {{-- Empty state --}}
            <template x-if="false">
                <div class="lb-empty">
                    <div class="lb-empty-icon"><i data-lucide="search" aria-hidden="true"></i></div>
                    <div class="lb-empty-title">Belum ada aktivitas di periode ini</div>
                    <div class="lb-empty-sub">Mulailah triage alert untuk tampil di leaderboard dan raih penghargaan!</div>
                </div>
            </template>

            {{-- Spacer under podium blocks --}}
        </section>

        <section class="card lb-mix-card" x-show="hasActivity" x-cloak>
            <div class="lb-panel-header">
                <div>
                    <div class="lb-section-title">Komposisi tindakan</div>
                    <div class="lb-panel-subtitle">Distribusi aktivitas tim pada periode aktif</div>
                </div>
            </div>
            <div class="lb-mix-body">
                <div class="lb-mix-row">
                    <div><span>Notifikasi</span><strong x-text="teamTotals.notify"></strong></div>
                    <span class="lb-mix-meter"><span :style="'width:' + actionPercent(teamTotals.notify) + '%' "></span></span>
                </div>
                <div class="lb-mix-row">
                    <div><span>Filter alert</span><strong x-text="teamTotals.ignore"></strong></div>
                    <span class="lb-mix-meter"><span :style="'width:' + actionPercent(teamTotals.ignore) + '%' "></span></span>
                </div>
                <div class="lb-mix-row">
                    <div><span>Acknowledge</span><strong x-text="teamTotals.acknowledge"></strong></div>
                    <span class="lb-mix-meter"><span :style="'width:' + actionPercent(teamTotals.acknowledge) + '%' "></span></span>
                </div>
            </div>
        </section>
        </div>

        <section class="lb-zero-state card" x-show="!hasActivity" x-cloak>
            <span class="lb-zero-icon"><i data-lucide="activity" aria-hidden="true"></i></span>
            <div>
                <h2>Belum ada aksi SOC pada periode ini</h2>
                <p>Leaderboard dihitung dari acknowledge, filter alert, dan notifikasi yang tercatat.</p>
            </div>
            <a href="{{ route('alerts.index') }}" class="btn btn-primary btn-sm">
                <i data-lucide="bell" aria-hidden="true"></i> Buka Live Alerts
            </a>
        </section>

        {{-- ═══════════════════════════════════════════════════════════
             CHARTS SECTION — Notifikasi & Filter (2 col)
        ═══════════════════════════════════════════════════════════ --}}
        <div class="charts-grid" x-show="hasActivity" x-cloak>

            {{-- Notify Chart --}}
            <div class="card" x-show="hasMetricActivity('notify')" x-cloak>
                <div class="card-header">
                    <i data-lucide="send" aria-hidden="true"></i>
                    <span class="card-title">Ranking Notifikasi Telegram</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrapper" id="notify-chart-wrap">
                        <canvas id="notifyChart"></canvas>
                    </div>
                    <template x-if="(data?.by_notify ?? []).every(a => a.notify === 0)">
                        <div class="lb-empty" style="padding: 1.5rem;">
                            <div class="lb-empty-icon"><i data-lucide="send" aria-hidden="true"></i></div>
                            <div style="font-size:0.82rem;">Belum ada notifikasi dikirim</div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Ignore / Filter Chart --}}
            <div class="card" x-show="hasMetricActivity('ignore')" x-cloak>
                <div class="card-header">
                    <i data-lucide="shield-alert" aria-hidden="true"></i>
                    <span class="card-title">Ranking Filter Alert</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrapper" id="ignore-chart-wrap">
                        <canvas id="ignoreChart"></canvas>
                    </div>
                    <template x-if="(data?.by_ignore ?? []).every(a => a.ignore === 0)">
                        <div class="lb-empty" style="padding: 1.5rem;">
                            <div class="lb-empty-icon"><i data-lucide="shield-alert" aria-hidden="true"></i></div>
                            <div style="font-size:0.82rem;">Belum ada filter dilakukan</div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             Acknowledge Chart (full width)
        ═══════════════════════════════════════════════════════════ --}}
        <div class="card mb-4" x-show="hasMetricActivity('ack')" x-cloak>
            <div class="card-header">
                <i data-lucide="check" aria-hidden="true"></i>
                <span class="card-title">Ranking Acknowledge Alert</span>
            </div>
            <div class="card-body">
                <div class="chart-wrapper" id="ack-chart-wrap">
                    <canvas id="ackChart"></canvas>
                </div>
                <template x-if="(data?.by_ack ?? []).every(a => a.acknowledge === 0)">
                    <div class="lb-empty" style="padding: 1.5rem;">
                        <div class="lb-empty-icon"><i data-lucide="check" aria-hidden="true"></i></div>
                        <div style="font-size:0.82rem;">Belum ada acknowledge dilakukan</div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             FULL ANALYST TABLE
        ═══════════════════════════════════════════════════════════ --}}
        <div class="card">
            <div class="card-header">
                <i data-lucide="activity" aria-hidden="true"></i>
                <span class="card-title">Rekap Operator SOC</span>
                <span class="text-muted text-sm ml-auto"
                    x-text="'Total ' + (data?.by_total?.length ?? 0) + ' operator'"></span>
            </div>
            <div style="overflow-x: auto;">
                <table class="data-table lb-table">
                    <thead>
                        <tr>
                            <th style="width:50px; text-align:center;">#</th>
                            <th>Operator</th>
                            <th style="text-align:center;">Notif</th>
                            <th style="text-align:center;">Filter</th>
                            <th style="text-align:center;">Ack</th>
                            <th style="text-align:center;">Total</th>
                            <th style="width:130px; text-align:center;">Tren Aksi</th>
                            <th>Status aktivitas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(analyst, index) in (data?.by_total ?? [])" :key="analyst.user_id">
                            <tr :class="{ 'my-row': analyst.user_id === currentUserId }">

                                {{-- Rank --}}
                                <td style="text-align:center;">
                                    <span class="rank-medal" x-text="rankLabel(index, analyst)"></span>
                                </td>

                                {{-- Analyst info --}}
                                <td>
                                    <div class="analyst-cell">
                                        <div class="table-avatar" x-text="analyst.initials"></div>
                                        <div>
                                            <div class="analyst-name-text" x-text="analyst.name"></div>
                                            <div class="analyst-role-text" x-text="analyst.role"></div>
                                        </div>
                                        <span x-show="analyst.user_id === currentUserId" class="you-tag">Anda</span>
                                    </div>
                                </td>

                                {{-- Stats --}}
                                <td style="text-align:center;">
                                    <span class="stat-notify" x-text="analyst.notify"></span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="stat-ignore" x-text="analyst.ignore"></span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="stat-ack" x-text="analyst.acknowledge"></span>
                                </td>
                                <td style="text-align:center;">
                                    <span class="stat-total" x-text="analyst.total"></span>
                                </td>

                                {{-- Sparkline --}}
                                <td style="text-align:center; padding: 0.5rem 0.75rem;">
                                    <canvas :id="'spark-' + analyst.user_id"
                                        width="120" height="38"
                                        style="display:block; margin:auto;"></canvas>
                                </td>

                                {{-- Badges --}}
                                <td>
                                    <span class="lb-contribution-state"
                                        :class="{ 'is-active': analyst.total > 0 }"
                                        x-text="analyst.total > 0 ? 'Aktif pada periode ini' : 'Belum ada aktivitas'"></span>
                                    <div class="badge-chips" x-show="false" x-cloak>
                                        <template x-for="b in analyst.badges" :key="b.key">
                                            <span class="badge-chip"
                                                :class="b.key"
                                                :title="b.desc">
                                                <span x-text="b.icon"></span>
                                                <span class="badge-label" x-text="b.label"></span>
                                            </span>
                                        </template>
                                        <span x-show="analyst.badges.length === 0"
                                            style="font-size:0.72rem; color:var(--text-secondary);">—</span>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        {{-- Empty rows state --}}
                        <template x-if="(data?.by_total ?? []).length === 0">
                            <tr>
                                <td colspan="8">
                                    <div class="lb-empty">
                                        <div class="lb-empty-icon"><i data-lucide="users" aria-hidden="true"></i></div>
                                        <div class="lb-empty-title">Belum ada data analyst</div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Table footer: legend --}}
            <div style="padding: 0.875rem 1.25rem; border-top: 1px solid var(--border); display:flex; gap:1.25rem; flex-wrap:wrap;">
                <span style="font-size:0.72rem; color:var(--text-secondary);">
                    <span style="color: var(--lb-notify); font-weight:700;">Notif</span> = Notifikasi Telegram tercatat
                </span>
                <span style="font-size:0.72rem; color:var(--text-secondary);">
                    <span style="color: var(--lb-ignore); font-weight:700;">Filter</span> = Alert difilter (ignore)
                </span>
                <span style="font-size:0.72rem; color:var(--text-secondary);">
                    <span style="color: var(--lb-ack); font-weight:700;">Ack</span> = Alert di-acknowledge
                </span>
                <span style="font-size:0.72rem; color:var(--text-secondary); margin-left:auto;">
                    Tren Aksi = total aktivitas per interval di periode terpilih
                </span>
            </div>
        </div>

    </div><!-- /!loading && data -->

</div><!-- /x-data -->
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
function leaderboard(currentUserId) {
    return {
        currentUserId: currentUserId,
        period: 'week',
        dateFrom: '',
        dateTo: '',
        loading: false,
        ready: false,       // flip to true once data is available and charts rendered
        error: '',
        data: null,
        activeTab: 'total',
        charts: {},

        /* ─── Init ─── */
        async init() {
            const today = new Date().toISOString().split('T')[0];
            this.dateFrom = today;
            this.dateTo   = today;
            document.addEventListener('siem:themechange', () => {
                if (!this.ready) return;
                this.$nextTick(() => {
                    this.renderCharts();
                    this.renderSparklines();
                });
            });

            // Read pre-loaded data from JSON script tag (safe, no JS syntax risk)
            const script = document.getElementById('lb-initial-data');
            if (script && script.textContent.trim()) {
                try {
                    this.data = JSON.parse(script.textContent);
                } catch (e) {
                    console.warn('[Leaderboard] JSON.parse failed:', e);
                }
            }

            if (this.data) {
                this.ready = true;          // show content div immediately
                await this.$nextTick();
                this.renderCharts();
                this.renderSparklines();
            } else {
                // Fallback: fetch from API (e.g. if server-side compute failed)
                await this.fetchData();
            }
        },

        /* ─── Period setter ─── */
        setPeriod(p) {
            this.period = p;
            if (p !== 'custom') this.fetchData();
        },

        /* ─── Fetch from API (period changes + fallback) ─── */
        async fetchData() {
            if (this.period === 'custom' && (!this.dateFrom || !this.dateTo || this.dateFrom > this.dateTo)) {
                this.error = 'Rentang tanggal belum valid. Pastikan tanggal awal tidak melewati tanggal akhir.';
                return;
            }

            const hasExistingData = Boolean(this.data);
            this.loading = true;
            this.error   = '';
            if (!hasExistingData) this.ready = false;
            this.destroyAllCharts();
            try {
                const params = new URLSearchParams({ period: this.period });
                if (this.period === 'custom') {
                    params.set('date_from', this.dateFrom);
                    params.set('date_to',   this.dateTo);
                }
                const res = await fetch(`/api/leaderboard?${params}`);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                this.data  = await res.json();
                this.ready = true;
                await this.$nextTick();
                this.renderCharts();
                this.renderSparklines();
            } catch (e) {
                console.error('[Leaderboard] Fetch error:', e);
                this.error = 'Data leaderboard belum dapat dimuat. Silakan coba lagi.';
                this.ready = hasExistingData;
                if (hasExistingData) {
                    await this.$nextTick();
                    this.renderCharts();
                    this.renderSparklines();
                }
            } finally {
                this.loading = false;
            }
        },

        /* ─── Computed: current ranking based on active tab ─── */
        get analysts() {
            return this.data?.by_total ?? [];
        },

        get activeAnalysts() {
            return this.analysts.filter(analyst => Number(analyst.total) > 0);
        },

        get hasActivity() {
            return this.activeAnalysts.length > 0;
        },

        get teamTotals() {
            return this.analysts.reduce((totals, analyst) => ({
                total: totals.total + Number(analyst.total || 0),
                active: totals.active + (Number(analyst.total || 0) > 0 ? 1 : 0),
                notify: totals.notify + Number(analyst.notify || 0),
                ignore: totals.ignore + Number(analyst.ignore || 0),
                acknowledge: totals.acknowledge + Number(analyst.acknowledge || 0),
                triage: totals.triage + Number(analyst.ignore || 0) + Number(analyst.acknowledge || 0),
            }), { total: 0, active: 0, notify: 0, ignore: 0, acknowledge: 0, triage: 0 });
        },

        currentMetricKey() {
            return { total: 'total', notify: 'notify', ignore: 'ignore', ack: 'acknowledge' }[this.activeTab] || 'total';
        },

        currentMetricLabel() {
            return { total: 'total aksi', notify: 'notifikasi', ignore: 'filter alert', ack: 'acknowledge' }[this.activeTab] || 'total aksi';
        },

        hasMetricActivity(metric) {
            const key = { notify: 'notify', ignore: 'ignore', ack: 'acknowledge', total: 'total' }[metric] || metric;
            return this.analysts.some(analyst => Number(analyst[key] || 0) > 0);
        },

        actionPercent(value) {
            const peak = Math.max(this.teamTotals.notify, this.teamTotals.ignore, this.teamTotals.acknowledge, 1);
            return Math.round((Number(value || 0) / peak) * 100);
        },

        rankPercent(analyst) {
            const leader = this.currentRanking[0];
            const top = Number(leader ? this.currentValue(leader) : 0) || 1;
            return Math.round((Number(this.currentValue(analyst)) / top) * 100);
        },

        rankLabel(index, analyst) {
            return Number(analyst.total || 0) > 0 ? String(index + 1).padStart(2, '0') : '-';
        },

        get currentRanking() {
            if (!this.data) return [];
            const keyMap = { total: 'by_total', notify: 'by_notify', ignore: 'by_ignore', ack: 'by_ack' };
            const valueKey = this.currentMetricKey();
            return (this.data[keyMap[this.activeTab] || 'by_total'] || [])
                .filter(analyst => Number(analyst[valueKey] || 0) > 0)
                .slice(0, 3);
        },

        currentValue(analyst) {
            if (!analyst) return '—';
            const v = { total: analyst.total, notify: analyst.notify, ignore: analyst.ignore, ack: analyst.acknowledge };
            return v[this.activeTab] ?? analyst.total;
        },

        currentUnit() {
            const u = { total: 'aksi', notify: 'notif', ignore: 'filter', ack: 'ack' };
            return u[this.activeTab] || 'aksi';
        },

        /* ─── Chart rendering helpers ─── */
        isDark() {
            return document.documentElement.classList.contains('dark');
        },

        prefersReducedMotion() {
            return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
        },

        chartColors() {
            const styles = getComputedStyle(document.documentElement);
            const token = (name) => styles.getPropertyValue(name).trim();
            return {
                text: token('--color-text-secondary'),
                grid: token('--color-border-default'),
                label: token('--color-text-primary'),
                notify: token('--color-accent'),
                ignore: token('--color-success'),
                ack: token('--color-info'),
                tooltipBg: token('--color-surface-1'),
                tooltipBorder: token('--color-border-default'),
                tooltipTitle: token('--color-text-primary'),
                tooltipBody: token('--color-text-secondary'),
            };
        },

        buildBarOptions(colors, yTickColor) {
            return {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: this.prefersReducedMotion() ? false : { duration: 220, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: colors.tooltipBg,
                        borderColor:     colors.tooltipBorder,
                        borderWidth:     1,
                        titleColor:      colors.tooltipTitle,
                        bodyColor:       colors.tooltipBody,
                        padding:         10,
                        callbacks: {
                            label: (ctx) => `  ${ctx.raw} aksi`,
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { color: colors.text,  font: { family: 'Inter', size: 11 } },
                        grid:  { color: colors.grid },
                    },
                    y: {
                        ticks: { color: yTickColor || colors.label, font: { family: 'Inter', size: 12, weight: '600' } },
                        grid:  { display: false },
                    },
                },
            };
        },

        destroyChart(id) {
            if (this.charts[id]) { this.charts[id].destroy(); delete this.charts[id]; }
        },

        destroyAllCharts() {
            Object.keys(this.charts).forEach(id => this.destroyChart(id));
        },

        setChartHeight(wrapperId, numBars) {
            const el = document.getElementById(wrapperId);
            if (el) el.style.height = Math.max(120, numBars * 50 + 30) + 'px';
        },

        /* ─── Render all three bar charts ─── */
        renderCharts() {
            if (!this.data) return;
            const colors = this.chartColors();

            // Helper to render a single horizontal bar chart
            const render = (id, wrapperId, sortedKey, valueKey, color, label) => {
                this.destroyChart(id);
                const canvas = document.getElementById(id);
                if (!canvas) return;

                const sorted  = (this.data[sortedKey] || []).filter(a => a[valueKey] > 0);
                if (sorted.length === 0) { canvas.style.display = 'none'; return; }
                canvas.style.display = '';

                this.setChartHeight(wrapperId, sorted.length);

                this.charts[id] = new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: sorted.map(a => a.name),
                        datasets: [{
                            label: label,
                            data:  sorted.map(a => a[valueKey]),
                            backgroundColor: color + 'cc',
                            hoverBackgroundColor: color,
                            borderColor: color,
                            borderWidth: 0,
                            borderRadius: 6,
                            borderSkipped: false,
                        }],
                    },
                    options: this.buildBarOptions(colors),
                });
            };

            render('notifyChart', 'notify-chart-wrap', 'by_notify', 'notify',      colors.notify, 'Notifikasi');
            render('ignoreChart', 'ignore-chart-wrap', 'by_ignore', 'ignore',      colors.ignore, 'Filter');
            render('ackChart',    'ack-chart-wrap',    'by_ack',    'acknowledge', colors.ack,    'Acknowledge');
        },

        /* ─── Render sparklines ─── */
        renderSparklines() {
            if (!this.data?.by_total) return;
            const colors   = this.chartColors();
            const notifCol = colors.notify;

            this.data.by_total.forEach(analyst => {
                const id     = 'spark-' + analyst.user_id;
                this.destroyChart(id);
                const canvas = document.getElementById(id);
                if (!canvas) return;

                const sparkData   = analyst.sparkline        ?? Array(7).fill(0);
                const sparkLabels = analyst.sparkline_labels ?? [];
                const hasActivity = sparkData.some(v => v > 0);

                this.charts[id] = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: sparkLabels,
                        datasets: [{
                            data: sparkData,
                            borderColor:     hasActivity ? notifCol : colors.grid,
                            backgroundColor: hasActivity ? notifCol + '28' : 'transparent',
                            borderWidth: 1.5,
                            fill:    true,
                            tension: 0.4,
                            pointRadius: 0,
                            pointHoverRadius: 3,
                            pointHoverBackgroundColor: notifCol,
                        }],
                    },
                    options: {
                        responsive:          false,
                        animation:           this.prefersReducedMotion() ? false : { duration: 160 },
                        plugins: {
                            legend:  { display: false },
                            tooltip: { enabled: false },
                        },
                        scales: {
                            x: { display: false },
                            y: { display: false, beginAtZero: true },
                        },
                    },
                });
            });
        },
    };
}
</script>
@endpush
