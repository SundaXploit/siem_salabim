@extends('layouts.app')
@section('body-class', 'density-default')

@section('title', 'Kinerja Tim SOC')
@section('page-context', 'Kinerja Operasi')
@section('page-title', 'Kinerja Tim SOC')
@section('page-subtitle', 'Ringkasan aktivitas triage, filter, dan notifikasi')

@push('styles')
@endpush

@push('styles')
@endpush

@section('content')
{{-- Pre-loaded data: safer than inline JS embedding --}}
<script id="lb-initial-data" type="application/json">@json($initialData)</script>

<div class="leaderboard-page" x-data="leaderboard({{ auth()->id() }})">

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
            <label style="font-size:var(--text-sm); font-weight:var(--font-weight-bold); color:var(--text-secondary); line-height:var(--leading-sm);">Dari</label>
            <input type="date" x-model="dateFrom" id="lb-date-from">
            <label style="font-size:var(--text-sm); font-weight:var(--font-weight-bold); color:var(--text-secondary); line-height:var(--leading-sm);">s/d</label>
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
                <span class="lb-summary-label">Total aksi</span>
                <strong class="lb-summary-value" x-text="teamTotals.total"></strong>
                <span class="lb-summary-note">Seluruh aktivitas tercatat</span>
            </article>
            <article class="lb-summary-card">
                <span class="lb-summary-label">Operator aktif</span>
                <strong class="lb-summary-value" x-text="teamTotals.active"></strong>
                <span class="lb-summary-note" x-text="'dari ' + (data?.by_total?.length ?? 0) + ' operator' "></span>
            </article>
            <article class="lb-summary-card">
                <span class="lb-summary-label">Triage</span>
                <strong class="lb-summary-value" x-text="teamTotals.triage"></strong>
                <span class="lb-summary-note">Acknowledge dan filter</span>
            </article>
            <article class="lb-summary-card">
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
                        <div class="podium-name" x-text="currentRanking[1]?.name ?? '-'"></div>
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
                        <div class="podium-name" x-text="currentRanking[0]?.name ?? '-'"></div>
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
                        <div class="podium-name" x-text="currentRanking[2]?.name ?? '-'"></div>
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
                Buka Live Alerts
            </a>
        </section>

        {{-- ═══════════════════════════════════════════════════════════
             CHARTS SECTION - Notifikasi & Filter (2 col)
        ═══════════════════════════════════════════════════════════ --}}
        <div class="charts-grid" x-show="hasActivity" x-cloak>

            {{-- Notify Chart --}}
            <div class="card" x-show="hasMetricActivity('notify')" x-cloak>
                <div class="card-header">
                    <span class="card-title">Ranking Notifikasi Telegram</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrapper" id="notify-chart-wrap">
                        <canvas id="notifyChart"></canvas>
                    </div>
                    <template x-if="(data?.by_notify ?? []).every(a => a.notify === 0)">
                        <div class="lb-empty" style="padding: var(--spacing-24);">
                            <div class="lb-empty-icon"><i data-lucide="send" aria-hidden="true"></i></div>
                            <div style="font-size:var(--text-sm);line-height:var(--leading-sm);">Belum ada notifikasi dikirim</div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Ignore / Filter Chart --}}
            <div class="card" x-show="hasMetricActivity('ignore')" x-cloak>
                <div class="card-header">
                    <span class="card-title">Ranking Filter Alert</span>
                </div>
                <div class="card-body">
                    <div class="chart-wrapper" id="ignore-chart-wrap">
                        <canvas id="ignoreChart"></canvas>
                    </div>
                    <template x-if="(data?.by_ignore ?? []).every(a => a.ignore === 0)">
                        <div class="lb-empty" style="padding: var(--spacing-24);">
                            <div class="lb-empty-icon"><i data-lucide="shield-alert" aria-hidden="true"></i></div>
                            <div style="font-size:var(--text-sm);line-height:var(--leading-sm);">Belum ada filter dilakukan</div>
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
                    <span class="card-title">Ranking Acknowledge Alert</span>
            </div>
            <div class="card-body">
                <div class="chart-wrapper" id="ack-chart-wrap">
                    <canvas id="ackChart"></canvas>
                </div>
                <template x-if="(data?.by_ack ?? []).every(a => a.acknowledge === 0)">
                    <div class="lb-empty" style="padding: var(--spacing-24);">
                        <div class="lb-empty-icon"><i data-lucide="check" aria-hidden="true"></i></div>
                        <div style="font-size:var(--text-sm);line-height:var(--leading-sm);">Belum ada acknowledge dilakukan</div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ═══════════════════════════════════════════════════════════
             FULL ANALYST TABLE
        ═══════════════════════════════════════════════════════════ --}}
        <div class="card">
            <div class="card-header">
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
                                <td style="text-align:center; padding: var(--spacing-8) var(--spacing-12);">
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
                                            style="font-size:var(--text-xs); color:var(--text-secondary); line-height:var(--leading-xs);">-</span>
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
            <div style="padding: var(--spacing-16) var(--spacing-24); border-top: 1px solid var(--border); display:flex; gap:var(--spacing-24); flex-wrap:wrap;">
                <span style="font-size:var(--text-xs); color:var(--text-secondary); line-height:var(--leading-xs);">
                    <span style="color: var(--lb-notify); font-weight:var(--font-weight-bold);">Notif</span> = Notifikasi Telegram tercatat
                </span>
                <span style="font-size:var(--text-xs); color:var(--text-secondary); line-height:var(--leading-xs);">
                    <span style="color: var(--lb-ignore); font-weight:var(--font-weight-bold);">Filter</span> = Alert difilter (ignore)
                </span>
                <span style="font-size:var(--text-xs); color:var(--text-secondary); line-height:var(--leading-xs);">
                    <span style="color: var(--lb-ack); font-weight:var(--font-weight-bold);">Ack</span> = Alert di-acknowledge
                </span>
                <span style="font-size:var(--text-xs); color:var(--text-secondary); margin-left:auto; line-height:var(--leading-xs);">
                    Tren Aksi = total aktivitas per interval di periode terpilih
                </span>
            </div>
        </div>

    </div><!-- /!loading && data -->

</div><!-- /x-data -->
@endsection

@push('scripts')
<script src="{{ asset('vendor/chart-4.4.4.umd.min.js') }}"></script>
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
        themeHandler: null,
        chartsRevealed: false,

        /* ─── Init ─── */
        async init() {
            const today = new Date().toISOString().split('T')[0];
            this.dateFrom = today;
            this.dateTo   = today;
            this.themeHandler = () => {
                if (!this.ready || !Object.keys(this.charts).length) return;
                this.chartsRevealed = true;
                this.$nextTick(() => {
                    this.renderCharts();
                    this.renderSparklines();
                });
            };
            document.addEventListener('siem:themechange', this.themeHandler);

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
        destroy() {
            this.destroyAllCharts();
            document.removeEventListener('siem:themechange', this.themeHandler);
        },
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
            if (!analyst) return '-';
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
            const rootSize = parseFloat(styles.fontSize);
            return {
                fontFamily: token('--font-sans'),
                captionSize: parseFloat(token('--ina-font-size-caption')) * rootSize,
                captionLineHeight: parseFloat(token('--ina-line-height-caption')) * rootSize + 'px',
                bodySize: parseFloat(token('--ina-font-size-body-small')) * rootSize,
                bodyLineHeight: parseFloat(token('--ina-line-height-body-small')) * rootSize + 'px',
                tooltipPadding: parseFloat(token('--spacing-12')) * rootSize,
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
                animation: this.prefersReducedMotion() || this.chartsRevealed ? false : { duration: 300, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: colors.tooltipBg,
                        borderColor:     colors.tooltipBorder,
                        borderWidth:     1,
                        titleColor:      colors.tooltipTitle,
                        bodyColor:       colors.tooltipBody,
                        padding: colors.tooltipPadding,
                        titleFont: { family: colors.fontFamily, size: colors.bodySize, lineHeight: colors.bodyLineHeight, weight: '700' },
                        bodyFont: { family: colors.fontFamily, size: colors.captionSize, lineHeight: colors.captionLineHeight },
                        callbacks: {
                            label: (ctx) => `  ${ctx.raw} aksi`,
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { color: colors.text, font: { family: colors.fontFamily, size: colors.captionSize, lineHeight: colors.captionLineHeight } },
                        grid:  { color: colors.grid },
                    },
                    y: {
                        ticks: { color: yTickColor || colors.label, font: { family: colors.fontFamily, size: colors.captionSize, lineHeight: colors.captionLineHeight, weight: '700' } },
                        grid:  { display: false },
                    },
                },
            };
        },

        destroyChart(id) {
            if (this.charts[id]) { this.charts[id].destroy(); delete this.charts[id]; }
        },

        destroyAllCharts() {
            if (Object.keys(this.charts).length) this.chartsRevealed = true;
            Object.keys(this.charts).forEach(id => this.destroyChart(id));
        },

        setChartHeight(wrapperId, numBars) {
            const el = document.getElementById(wrapperId);
            if (el) el.style.height = Math.max(120, numBars * 50 + 30) + 'px';
        },

        /* ─── Render all three bar charts ─── */
        renderCharts() {
            if (!this.data) return;
            if (typeof Chart === 'undefined') {
                this.error = 'Grafik tidak dapat dimuat. Data ranking tetap tersedia.';
                return;
            }
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
                            borderRadius: 3,
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
            if (typeof Chart === 'undefined') return;
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
                        animation:           this.prefersReducedMotion() || this.chartsRevealed ? false : { duration: 300 },
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
